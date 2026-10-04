<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Inventory status of a quantity at a node.
 *
 * AVAILABLE       sellable stock (sales in A4 draw from here only)
 * QUARANTINE      physically received, not sellable; used for goods written
 *                 off or regraded during receiving (nets to zero at posting)
 * RETURN_PENDING  set aside to go back to the supplier; not sellable, still
 *                 physically held until shipped (SUPPLIER_RETURN) or recovered
 *
 * Kept deliberately small. New buckets (DAMAGED, IN_TRANSIT …) are new cases.
 */
enum StockBucket: string
{
    case AVAILABLE = 'AVAILABLE';
    case QUARANTINE = 'QUARANTINE';
    case RETURN_PENDING = 'RETURN_PENDING';

    public function isSellable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
