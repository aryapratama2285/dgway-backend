<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity\Quantity;

/**
 * Deterministic FIFO: oldest lot first by (business date, received time,
 * lot number). FEFO is deliberately NOT used: best-before dates are optional
 * and absent for most products, and a FEFO order built on missing dates would
 * be fake. Pure function — the caller supplies candidates read under lock.
 */
final class FifoAllocator
{
    /**
     * @param  list<LotStock>  $candidates  lots holding stock in $position
     * @return list<LotPortion>
     *
     * @throws InsufficientStock when the lots together hold less than requested
     */
    public static function allocate(StockKey $position, array $candidates, Quantity $requested): array
    {
        $requested->assertPositive('Requested quantity');
        usort($candidates, fn (LotStock $a, LotStock $b) => [$a->businessDate->toString(), $a->receivedAt->format('Y-m-d\TH:i:s.u'), $a->lotNumber]
            <=> [$b->businessDate->toString(), $b->receivedAt->format('Y-m-d\TH:i:s.u'), $b->lotNumber]);

        $portions = [];
        $left = $requested;
        $available = Quantity::zero($requested->uom);
        foreach ($candidates as $lot) {
            if (! $lot->quantity->isPositive()) {
                continue;
            }
            $available = $available->plus($lot->quantity);
            if ($left->isZero()) {
                continue;
            }
            $take = $lot->quantity->isLessThan($left) ? $lot->quantity : $left;
            $portions[] = new LotPortion($lot->lotId, $take);
            $left = $left->minus($take);
        }
        if ($left->isPositive()) {
            throw InsufficientStock::at($position, $available, $requested);
        }

        return $portions;
    }
}
