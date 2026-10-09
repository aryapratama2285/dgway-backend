<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

/**
 * F4A: why a purchase order was CLOSED although part of it may remain
 * unreceived. CLOSE ≠ CANCEL: a closed order keeps its receipts and its
 * unfulfilled quantity as history; a cancelled order never proceeded.
 */
enum PurchaseOrderCloseReason: string
{
    case SUPPLIER_SHORTFALL = 'SUPPLIER_SHORTFALL';
    case PURCHASE_STOPPED = 'PURCHASE_STOPPED';
    case OTHER = 'OTHER';

    public function requiresNote(): bool
    {
        return $this === self::OTHER;
    }
}
