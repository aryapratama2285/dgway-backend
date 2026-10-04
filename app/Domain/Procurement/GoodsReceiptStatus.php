<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

/**
 *   DRAFT ──post──▶ POSTED ──reverse──▶ REVERSED
 *     └──cancel──▶ CANCELLED
 *
 * Only POSTED receipts have stock movements. A POSTED receipt is never edited
 * or deleted; mistakes are corrected by reversing it and posting a new one.
 */
enum GoodsReceiptStatus: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case REVERSED = 'REVERSED';
    case CANCELLED = 'CANCELLED';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::DRAFT => [self::POSTED, self::CANCELLED],
            self::POSTED => [self::REVERSED],
            self::REVERSED, self::CANCELLED => [],
        }, true);
    }
}
