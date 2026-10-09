<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use DateTimeImmutable;

/**
 * Records what physically arrived and what is decided for each part of it,
 * as a DRAFT receipt. Nothing touches inventory until PostGoodsReceipt.
 *
 * Works for both paths:
 *   - PO-based: purchase_order_id + purchase_order_line_id per line;
 *     unit cost defaults to the PO line's agreed cost;
 *   - direct purchase: no PO; unit cost is required per line.
 * Lines (units, conversion snapshot, cost defaults) are built by {@see ReceiptLineFactory}.
 *
 * @phpstan-type DispositionInput array{type: string, quantity: string|int, target_product_id?: ?string, reason?: ?string}
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_cost?: string|int|null,
 *                               purchase_order_line_id?: ?string, dispositions: list<DispositionInput>}
 */
final class CreateGoodsReceipt
{
    public const PREFIX = 'GR';

    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly ReceiptLineFactory $lineFactory,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param list<LineInput> $lines */
    public function execute(
        Actor $actor,
        string $supplierId,
        string $nodeId,
        array $lines,
        ?string $purchaseOrderId = null,
        ?string $businessDate = null,
        ?string $receivedAt = null,
        ?string $notes = null,
    ): GoodsReceipt {
        return $this->tx->run(function () use ($actor, $supplierId, $nodeId, $lines, $purchaseOrderId, $businessDate, $receivedAt, $notes): GoodsReceipt {
            $supplier = $this->guard->activeSupplier($supplierId);
            $node = $this->guard->nodeWith($nodeId, NodeCapability::RECEIVING, NodeCapability::INVENTORY);
            $po = $purchaseOrderId === null ? null : $this->orders->get(Id::fromString($purchaseOrderId));
            $date = $businessDate === null ? $this->clock->today() : BusinessDate::fromString($businessDate);
            $at = $receivedAt === null ? $this->clock->now() : new DateTimeImmutable($receivedAt);

            $grLines = $this->lineFactory->build($lines, $po);

            $gr = GoodsReceipt::draft($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $supplier->id(), $node->id(), $date, $at, $grLines, $po, $notes);
            $this->receipts->add($gr, $actor->userId);
            $this->audit->created($actor, 'goods_receipt.created', 'goods_receipt', $gr);

            return $gr;
        });
    }
}
