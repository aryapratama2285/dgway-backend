<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Whether a position may go below zero. A3 default: never
 * ({@see DisallowNegativeStock}). Kept as a policy object so a future
 * "allow negative AVAILABLE for back-orders" rule is a new class, not a
 * rewrite of the posting logic.
 */
interface NegativeStockPolicy
{
    public function allowsNegative(StockKey $key): bool;
}
