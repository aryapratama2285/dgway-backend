<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;

/** "Take this much from that lot" — the result of an allocation. */
final class LotPortion
{
    public function __construct(
        public readonly Id $lotId,
        public readonly Quantity $quantity,
    ) {
        $quantity->assertPositive('Lot portion');
    }
}
