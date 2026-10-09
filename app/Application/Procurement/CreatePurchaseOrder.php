<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock as BusinessClock;

/**
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_cost: string|int}
 *   `uom` = purchasing unit (F4A, default base unit), `unit_cost` = agreed price per that unit
 */
final class CreatePurchaseOrder
{
    public const PREFIX = 'PO';

    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly PurchaseOrderLineFactory $lineFactory,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly BusinessClock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param list<LineInput> $lines */
    public function execute(Actor $actor, string $supplierId, string $nodeId, array $lines, ?string $businessDate = null, ?string $notes = null): PurchaseOrder
    {
        return $this->tx->run(function () use ($actor, $supplierId, $nodeId, $lines, $businessDate, $notes): PurchaseOrder {
            $supplier = $this->guard->activeSupplier($supplierId);
            $node = $this->guard->nodeWith($nodeId, NodeCapability::RECEIVING, NodeCapability::INVENTORY);
            $date = $businessDate === null ? $this->clock->today() : BusinessDate::fromString($businessDate);

            $poLines = $this->lineFactory->build($lines);

            $po = PurchaseOrder::draft($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $supplier->id(), $node->id(), $date, $poLines, $notes);
            $this->orders->add($po, $actor->userId);
            $this->audit->created($actor, 'purchase_order.created', 'purchase_order', $po);

            return $po;
        });
    }
}
