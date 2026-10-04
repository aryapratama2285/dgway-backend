<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Direction of a stock movement. The ledger stores direction + a strictly
 * positive magnitude rather than a signed quantity: a sign error in code can
 * then never silently turn a receipt into a write-off, because the database
 * also checks that each movement type moves in its allowed direction.
 */
enum Direction: int
{
    case IN = 1;
    case OUT = -1;

    public function opposite(): self
    {
        return $this === self::IN ? self::OUT : self::IN;
    }
}
