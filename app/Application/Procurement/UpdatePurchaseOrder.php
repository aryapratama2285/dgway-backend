<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\BusinessDate;

/**
 * F4A: edit a DRAFT purchase order. Absent fields stay unchanged; `lines`, when
 * sent, REPLACES all lines (each line is re-validated and gets the current
 * conversion). Confirmed (or later) orders are refused with 409
 * INVALID_STATUS_TRANSITION — their terms are frozen (domain + DB trigger).
 */
final class UpdatePurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly PurchaseOrderLineFactory $lineFactory,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $id, Patch $patch, ?int $expectedVersion = null): PurchaseOrder
    {
        return $this->tx->run(function () use ($actor, $id, $patch, $expectedVersion): PurchaseOrder {
            $po = $this->orders->getForUpdate(Id::fromString($id));
            ExpectedVersion::check('Purchase order', $po, $expectedVersion);
            $before = $po->snapshot();

            $supplierId = $patch->has('supplier_id') ? $this->guard->activeSupplier((string) $patch->get('supplier_id'))->id() : $po->supplierId;
            $nodeId = $patch->has('node_id')
                ? $this->guard->nodeWith((string) $patch->get('node_id'), NodeCapability::RECEIVING, NodeCapability::INVENTORY)->id()
                : $po->nodeId;
            $date = $patch->has('business_date') ? BusinessDate::fromString((string) $patch->get('business_date')) : $po->businessDate;
            $notes = $patch->has('notes') ? $patch->string('notes') : $po->notes();
            $lines = $patch->has('lines') ? $this->lineFactory->build(array_values($patch->array('lines') ?? [])) : $po->lines();

            $revised = PurchaseOrder::revisedDraft($po, $supplierId, $nodeId, $date, $lines, $notes);
            $this->orders->saveDraft($revised, $patch->has('lines'));
            $this->audit->changed($actor, 'purchase_order.updated', 'purchase_order', $revised, $before);

            return $revised;
        });
    }
}
