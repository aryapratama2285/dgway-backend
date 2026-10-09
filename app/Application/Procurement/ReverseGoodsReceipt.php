<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Inventory\InventoryPoster;
use App\Application\Inventory\LedgerReversal;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Domain\Shared\Time\Clock;

/**
 * Reverses a POSTED receipt without touching history:
 *   - every movement the receipt produced gets a compensating REVERSAL
 *     movement (opposite direction, linked by reverses_movement_id, which is
 *     UNIQUE in the database → a movement can never be reversed twice);
 *   - reversals are posted newest-first, so intermediate states stay valid;
 *   - if the stock has meanwhile been used (e.g. returned to supplier, sold
 *     in A4), the reversal would go negative → INSUFFICIENT_STOCK and nothing
 *     is written;
 *   - the purchase order's received quantity is reduced again — refused when
 *     the order is CLOSED (F4A: terminal, read-only);
 *   - needs the `procurement.reverse` permission (F4A).
 * The correct receipt can then be posted as a new document.
 */
final class ReverseGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly LedgerReversal $reversal,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $receiptId, string $reason, ?int $expectedVersion = null): GoodsReceipt
    {
        $this->authorizer->authorize($actor, Permission::REVERSE_GOODS_RECEIPT);

        return $this->tx->run(function () use ($actor, $receiptId, $reason, $expectedVersion): GoodsReceipt {
            $gr = $this->receipts->getForUpdate(Id::fromString($receiptId));
            ExpectedVersion::check('Goods receipt', $gr, $expectedVersion);
            $before = $gr->snapshot();
            $now = $this->clock->now();
            $gr->reverse($now, $reason);

            if ($gr->purchaseOrderId !== null) {
                $po = $this->orders->getForUpdate($gr->purchaseOrderId);
                foreach ($gr->lines() as $line) {
                    $po->reverseReceipt($line->purchaseOrderLineId, $line->receivedQuantity);
                }
                $this->orders->save($po, $actor->userId);
            }

            $source = new SourceDocument('goods_receipt', $gr->id());
            $reversals = $this->reversal->reverseSource($source->type, $gr->id(), $actor->userId, $reason);
            $this->receipts->save($gr, $actor->userId);

            $this->audit->changed($actor, 'goods_receipt.reversed', 'goods_receipt', $gr, $before, ['reason' => $reason]);
            $this->recorder->record(new AuditEntry($actor, 'inventory.reversal', 'goods_receipt', $gr->id()->value, null,
                ['movements' => InventoryPoster::summarize($reversals)], ['goods_receipt' => $gr->number->toString(), 'source' => $source->type]));

            return $gr;
        });
    }
}
