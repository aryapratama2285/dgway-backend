<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * Sale lifecycle.
 *
 *   DRAFT ──confirm──▶ CONFIRMED ──fully settled──▶ COMPLETED
 *     │                   │  ▲                         │
 *     │                   │  └──payment reversed───────┘
 *     │                   └──────reverse──▶ REVERSED ◀──reverse──┘
 *     └──cancel──▶ CANCELLED
 *
 * DRAFT      nothing has happened yet; may be cancelled.
 * CONFIRMED  FINAL: stock left the node (SALE movements), money still owed.
 * COMPLETED  FINAL and fully settled (outstanding = 0). Goes back to
 *            CONFIRMED only if a payment is reversed.
 * REVERSED   compensated: stock returned by REVERSAL movements, no money kept.
 * A confirmed sale is never edited: corrections are customer returns or a
 * full reversal.
 */
enum SaleStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case REVERSED = 'REVERSED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::DRAFT => [self::CONFIRMED, self::COMPLETED, self::CANCELLED],
            self::CONFIRMED => [self::COMPLETED, self::REVERSED],
            self::COMPLETED => [self::CONFIRMED, self::REVERSED],
            self::CANCELLED, self::REVERSED => [],
        }, true);
    }

    /** Stock has left and money may be owed. */
    public function isFinal(): bool
    {
        return $this === self::CONFIRMED || $this === self::COMPLETED;
    }
}
