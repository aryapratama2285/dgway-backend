<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/** Cancel instead of delete: the PO stays as history with its reason. */
final class CancelPurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $id, string $reason, ?int $expectedVersion = null): PurchaseOrder
    {
        return $this->tx->run(function () use ($actor, $id, $reason, $expectedVersion): PurchaseOrder {
            $po = $this->orders->getForUpdate(Id::fromString($id));
            ExpectedVersion::check('Purchase order', $po, $expectedVersion);
            $before = $po->snapshot();
            $po->cancel($reason);
            $this->orders->save($po);
            $this->audit->changed($actor, 'purchase_order.cancelled', 'purchase_order', $po, $before);

            return $po;
        });
    }
}
