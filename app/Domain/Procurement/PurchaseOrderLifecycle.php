<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use DateTimeImmutable;

/**
 * F4A: when (and by whom) a purchase order was confirmed, cancelled or closed,
 * plus the close reason. Part of the PurchaseOrder aggregate; mutated only by
 * its lifecycle methods.
 *
 * @internal
 */
final class PurchaseOrderLifecycle
{
    public function __construct(
        public ?DateTimeImmutable $confirmedAt = null,
        public ?string $confirmedBy = null,
        public ?DateTimeImmutable $cancelledAt = null,
        public ?string $cancelledBy = null,
        public ?DateTimeImmutable $closedAt = null,
        public ?string $closedBy = null,
        public ?PurchaseOrderCloseReason $closeReason = null,
        public ?string $closeNote = null,
    ) {
    }
}
