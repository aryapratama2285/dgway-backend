<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

/**
 * Purchase order lifecycle.
 *
 *   DRAFT ──confirm──▶ CONFIRMED ──receipt──▶ PARTIALLY_RECEIVED ──receipt──▶ RECEIVED
 *     │                   │                        │    ▲                         │
 *     └──cancel──▶ CANCELLED ◀──cancel (nothing received)   └── receipt reversed ◀─┘
 *                                                  └──close──▶ CLOSED ◀──close────┘
 *
 * CLOSED (F4A) and CANCELLED are terminal and read-only. A receipt reversal
 * may move RECEIVED/PARTIALLY_RECEIVED back down (system transition), never a
 * CLOSED order.
 */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    case RECEIVED = 'RECEIVED';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::DRAFT => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED => [self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED],
            self::PARTIALLY_RECEIVED => [self::PARTIALLY_RECEIVED, self::RECEIVED, self::CONFIRMED, self::CLOSED],
            self::RECEIVED => [self::PARTIALLY_RECEIVED, self::CONFIRMED, self::CLOSED],
            self::CLOSED, self::CANCELLED => [],
        }, true);
    }

    public function acceptsReceipts(): bool
    {
        return $this === self::CONFIRMED || $this === self::PARTIALLY_RECEIVED;
    }

    public function isTerminal(): bool
    {
        return $this === self::CLOSED || $this === self::CANCELLED;
    }
}
