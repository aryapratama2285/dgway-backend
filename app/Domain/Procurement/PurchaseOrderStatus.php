<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

/**
 * Purchase order lifecycle.
 *
 *   DRAFT ──confirm──▶ CONFIRMED ──receipt──▶ PARTIALLY_RECEIVED ──receipt──▶ RECEIVED
 *     │                   │                        ▲    │                         │
 *     └──cancel──▶ CANCELLED ◀──cancel (nothing received)   └── receipt reversed ◀─┘
 *
 * RECEIVED and CANCELLED are terminal for user actions; a receipt reversal
 * may move RECEIVED/PARTIALLY_RECEIVED back down (system transition).
 */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    case RECEIVED = 'RECEIVED';
    case CANCELLED = 'CANCELLED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::DRAFT => [self::CONFIRMED, self::CANCELLED],
            self::CONFIRMED => [self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED],
            self::PARTIALLY_RECEIVED => [self::PARTIALLY_RECEIVED, self::RECEIVED, self::CONFIRMED],
            self::RECEIVED => [self::PARTIALLY_RECEIVED, self::CONFIRMED],
            self::CANCELLED => [],
        }, true);
    }

    public function acceptsReceipts(): bool
    {
        return $this === self::CONFIRMED || $this === self::PARTIALLY_RECEIVED;
    }
}
