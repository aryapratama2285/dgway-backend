<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final class DisallowNegativeStock implements NegativeStockPolicy
{
    public function allowsNegative(StockKey $key): bool
    {
        return false;
    }
}
