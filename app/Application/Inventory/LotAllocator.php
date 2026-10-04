<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\ProductRepository;
use App\Domain\Inventory\FifoAllocator;
use App\Domain\Inventory\LotPortion;
use App\Domain\Inventory\LotStock;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;

/**
 * Server-side FIFO lot allocation (clients never choose or compute lots).
 *
 * Concurrency: first locks every requested stock position in key order (the
 * same lock StockPosting takes), then reads the lots of each position FOR
 * UPDATE. Two concurrent consumers of one position therefore allocate one
 * after the other; the second sees the first one's consumption and cannot
 * over-consume a lot. Must run inside the caller's transaction, before
 * StockPosting::post of the resulting movements.
 */
final class LotAllocator
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * @param  list<array{0: StockKey, 1: Quantity}>  $requests  in business order (e.g. sale lines)
     * @return list<list<LotPortion>> one allocation per request, same order
     */
    public function allocate(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $uoms = [];
        $keys = [];
        foreach ($requests as [$key]) {
            $uoms[$key->productId->value] ??= $this->products->get(Id::fromString($key->productId->value))->baseUom();
            $keys[$key->toString()] = $key;
        }
        ksort($keys);
        $this->ledger->lockBalances(array_values($keys), $uoms);

        $lots = [];
        $out = [];
        foreach ($requests as [$key, $qty]) {
            $lots[$key->toString()] ??= $this->ledger->lotsInPosition($key, $uoms[$key->productId->value]);
            $portions = FifoAllocator::allocate($key, $lots[$key->toString()], $qty);
            $lots[$key->toString()] = self::consume($lots[$key->toString()], $portions);
            $out[] = $portions;
        }

        return $out;
    }

    /**
     * @param  list<LotStock>  $lots
     * @param  list<LotPortion>  $portions
     * @return list<LotStock>
     */
    private static function consume(array $lots, array $portions): array
    {
        $taken = [];
        foreach ($portions as $p) {
            $taken[$p->lotId->value] = $p->quantity;
        }

        return array_map(fn (LotStock $l) => isset($taken[$l->lotId->value])
            ? new LotStock($l->lotId, $l->lotNumber, $l->businessDate, $l->receivedAt, $l->quantity->minus($taken[$l->lotId->value]))
            : $l, $lots);
    }
}
