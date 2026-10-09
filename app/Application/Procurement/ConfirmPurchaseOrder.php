<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

final class ConfirmPurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
    ) {
    }

    public function execute(Actor $actor, string $id, ?int $expectedVersion = null): PurchaseOrder
    {
        return $this->tx->run(function () use ($actor, $id, $expectedVersion): PurchaseOrder {
            $po = $this->orders->getForUpdate(Id::fromString($id));
            ExpectedVersion::check('Purchase order', $po, $expectedVersion);

            // Master data may have been deactivated since the draft was written.
            $this->guard->activeSupplier($po->supplierId->value);
            $this->guard->nodeWith($po->nodeId->value, NodeCapability::RECEIVING, NodeCapability::INVENTORY);
            foreach ($po->lines() as $line) {
                $this->guard->activeProduct($line->productId->value);
            }

            $before = $po->snapshot();
            $po->confirm($this->clock->now(), $actor->userId);
            $this->orders->save($po, $actor->userId);
            $this->audit->changed($actor, 'purchase_order.confirmed', 'purchase_order', $po, $before);

            return $po;
        });
    }
}
