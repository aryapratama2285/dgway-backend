<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Inventory\InventoryPoster;
use App\Application\Inventory\LotRegistry;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Inventory\InventoryLot;
use App\Domain\Inventory\SourceDocument;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\DispositionType;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * Posts a DRAFT receipt: one transaction that
 *   1. row-locks the receipt (a concurrent post of the same receipt waits here
 *      and then fails with INVALID_STATUS_TRANSITION — stock is added once);
 *   2. re-checks master data (supplier, node, products still usable);
 *   3. updates the purchase order's received quantities (locked; over-receipt
 *      rejected even when two receipts against one PO are posted at once);
 *   4. writes the ledger movements and balances;
 *   5. audits.
 */
final class PostGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly ReceiptMovementPlanner $planner,
        private readonly InventoryPoster $inventory,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
        private readonly LotRegistry $registry,
        private readonly IdentityGenerator $ids,
    ) {
    }

    public function execute(Actor $actor, string $receiptId, ?int $expectedVersion = null): GoodsReceipt
    {
        return $this->tx->run(function () use ($actor, $receiptId, $expectedVersion): GoodsReceipt {
            $gr = $this->receipts->getForUpdate(Id::fromString($receiptId));
            ExpectedVersion::check('Goods receipt', $gr, $expectedVersion);
            $before = $gr->snapshot();
            $now = $this->clock->now();
            $gr->post($now);

            $this->guard->activeSupplier($gr->supplierId->value);
            $this->guard->nodeWith($gr->nodeId->value, NodeCapability::RECEIVING, NodeCapability::INVENTORY);
            foreach ($gr->lines() as $line) {
                $this->guard->activeProduct($line->productId->value);
                foreach ($line->dispositions as $d) {
                    if ($d->targetProductId !== null) {
                        $this->guard->activeProduct($d->targetProductId->value);
                    }
                }
            }

            if ($gr->purchaseOrderId !== null) {
                $po = $this->orders->getForUpdate($gr->purchaseOrderId);
                foreach ($gr->lines() as $line) {
                    $po->recordReceipt($line->purchaseOrderLineId, $line->receivedQuantity);
                }
                $this->orders->save($po, $actor->userId);
            }

            [$lotByLine, $derivedLots] = $this->createLots($gr, $actor);
            $movements = $this->planner->plan($gr, $now, $actor->userId, $lotByLine, $derivedLots);
            $this->inventory->post($movements);
            $this->receipts->save($gr, $actor->userId);

            $context = ['goods_receipt' => $gr->number->toString()];
            $this->audit->changed($actor, 'goods_receipt.posted', 'goods_receipt', $gr, $before);
            $this->recorder->record(new AuditEntry($actor, 'inventory.receipt', 'goods_receipt', $gr->id()->value, null, ['movements' => InventoryPoster::summarize($movements)], $context));
            foreach ($this->dispositionTotals($gr) as $action => $totals) {
                $this->recorder->record(new AuditEntry($actor, $action, 'goods_receipt', $gr->id()->value, null, ['lines' => $totals], $context));
            }

            return $gr;
        });
    }

    /**
     * Lot creation is decided here, by the server, at the moment a receipt is
     * POSTED: one lot per line (original quantity = received quantity, the
     * supplier and receipt as provenance), plus one derived lot per REGRADE
     * target. A retried/duplicate post never reaches this point (the receipt
     * row is locked and already POSTED), and the database allows only one
     * RECEIPT lot per receipt line.
     *
     * @return array{0: array<string, Id>, 1: array<string, Id>}
     */
    private function createLots(GoodsReceipt $gr, Actor $actor): array
    {
        $byLine = [];
        $derived = [];
        foreach ($gr->lines() as $line) {
            $lot = $this->registry->register(InventoryLot::fromReceipt(
                $this->ids->next(), $this->registry->nextNumber($gr->businessDate), $line->productId, $gr->nodeId, $line->receivedQuantity,
                $gr->supplierId, $gr->id(), $line->id, $gr->businessDate, $gr->receivedAt, $line->manufacturedDate, $line->bestBeforeDate,
                $line->collectionDate, $line->supplierReference,
            ), $actor);
            $byLine[$line->id->value] = $lot->id();
            $regraded = [];
            foreach ($line->dispositions as $d) {
                if ($d->type === DispositionType::REGRADE) {
                    $k = $d->targetProductId->value;
                    $regraded[$k] = isset($regraded[$k]) ? $regraded[$k]->plus($d->quantity) : $d->quantity;
                }
            }
            foreach ($regraded as $target => $qty) {
                $child = InventoryLot::derivedFrom($lot, $this->ids->next(), $this->registry->nextNumber($gr->businessDate), Id::fromString($target), $qty,
                    new SourceDocument('goods_receipt', $gr->id(), $line->id), $gr->businessDate, $gr->receivedAt);
                $derived[$line->id->value.'|'.$target] = $this->registry->register($child, $actor)->id();
            }
        }

        return [$byLine, $derived];
    }

    /** @return array<string, list<array<string, string>>> audit action → per-line quantities */
    private function dispositionTotals(GoodsReceipt $gr): array
    {
        $actions = [
            DispositionType::LOSS->value => 'inventory.loss',
            DispositionType::RETURN_TO_SUPPLIER->value => 'inventory.return_to_supplier',
            DispositionType::REGRADE->value => 'inventory.regrade',
        ];
        $out = [];
        foreach ($gr->lines() as $line) {
            foreach ($line->dispositions as $d) {
                if (isset($actions[$d->type->value])) {
                    $out[$actions[$d->type->value]][] = [
                        'line_no' => (string) $line->lineNo,
                        'product_id' => $line->productId->value,
                        'quantity' => $d->quantity->toStorage(),
                        'target_product_id' => (string) $d->targetProductId?->value,
                    ];
                }
            }
        }

        return $out;
    }
}
