<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderCloseReason;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * F4A: finish a PARTIALLY_RECEIVED or RECEIVED purchase order (CLOSED, terminal).
 * Ordered and received quantities stay as they are; the difference is shown
 * as the unfulfilled quantity. DRAFT receipts still open against the order
 * cannot be posted afterwards (PO_NOT_RECEIVABLE) — nothing is changed behind
 * the operator's back. The PO row lock serialises closing with a concurrent post.
 */
final class ClosePurchaseOrder
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Clock $clock,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $id, string $reasonCode, ?string $note = null, ?int $expectedVersion = null): PurchaseOrder
    {
        $this->authorizer->authorize($actor, Permission::CLOSE_PURCHASE_ORDER);
        $reason = PurchaseOrderCloseReason::tryFrom(strtoupper(trim($reasonCode)))
            ?? throw InvariantViolation::because('CLOSE_REASON_REQUIRED', "Unknown close reason '{$reasonCode}'.", ['field' => 'reason_code']);

        return $this->tx->run(function () use ($actor, $id, $reason, $note, $expectedVersion): PurchaseOrder {
            $po = $this->orders->getForUpdate(Id::fromString($id));
            ExpectedVersion::check('Purchase order', $po, $expectedVersion);
            $before = $po->snapshot();
            $po->close($reason, $note, $this->clock->now(), $actor->userId);
            $this->orders->save($po, $actor->userId);
            $this->audit->changed($actor, 'purchase_order.closed', 'purchase_order', $po, $before, [
                'reason_code' => $reason->value,
                'unfulfilled' => array_map(fn ($l) => ['line_no' => $l->lineNo, 'quantity' => $l->unfulfilledQuantity()->toStorage(), 'uom' => $l->orderedQuantity->uom->code], $po->lines()),
            ]);

            return $po;
        });
    }
}
