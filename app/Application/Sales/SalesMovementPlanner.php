<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\LotAllocator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use LogicException;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\StockBucket;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\CustomerReturn;
use App\Domain\Sales\ReturnDisposition;
use App\Domain\Sales\Sale;
use App\Domain\Shared\Time\Clock;

/**
 * Translates sales documents into A3 ledger movements (no second inventory
 * subsystem):
 *
 *   sale confirmed           SALE −q AVAILABLE per line (correlation = sale id)
 *   return → AVAILABLE       CUSTOMER_RETURN +q AVAILABLE
 *   return → QUARANTINE      CUSTOMER_RETURN +q QUARANTINE
 *   return → LOSS            CUSTOMER_RETURN +q QUARANTINE, LOSS −q QUARANTINE
 *
 * Movements carry no unit cost: selling price is revenue data and lives on
 * the sale line; cost/HPP policy is a later phase.
 */
final class SalesMovementPlanner
{
    public function __construct(
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly LotAllocator $allocator,
        private readonly SaleLotConsumption $consumption,
    ) {
    }

    /**
     * One SALE movement per (line, lot): the server allocates every line FIFO
     * over the lots in AVAILABLE, under the position lock.
     *
     * @return list<StockMovement>
     */
    public function forSale(Sale $sale, ?string $actorUserId): array
    {
        $lines = $sale->lines();
        $allocations = $this->allocator->allocate(array_map(
            fn ($line) => [new StockKey($line->productId, $sale->nodeId, StockBucket::AVAILABLE), $line->quantity], $lines));

        $out = [];
        foreach ($lines as $i => $line) {
            foreach ($allocations[$i] as $portion) {
                $out[] = StockMovement::record(
                    $this->ids->next(), MovementType::SALE, new StockKey($line->productId, $sale->nodeId, StockBucket::AVAILABLE), Direction::OUT,
                    $portion->quantity, $sale->businessDate, $sale->confirmedAt() ?? $this->clock->now(),
                    new SourceDocument('sale', $sale->id(), $line->id), $sale->id(), $actorUserId, "Sale {$sale->number->toString()}", null, null, $portion->lotId,
                );
            }
        }

        return $out;
    }

    /**
     * Returned goods go back into the lot(s) they were sold from. Policy
     * (deterministic, auditable): the returned quantity is attributed to the
     * sale line's lots in ALLOCATION ORDER (oldest consumed lot first), each
     * up to what that lot gave minus what already came back to it.
     *
     * @return list<StockMovement>
     */
    public function forReturn(CustomerReturn $return): array
    {
        $out = [];
        foreach ($return->lines as $line) {
            $bucket = $line->disposition === ReturnDisposition::RETURN_TO_AVAILABLE ? StockBucket::AVAILABLE : StockBucket::QUARANTINE;
            $key = new StockKey($line->productId, $return->nodeId, $bucket);
            foreach ($this->returnPortions($line->saleLineId, $line->quantity) as [$lotId, $qty]) {
                $mk = fn (MovementType $t, Direction $d) => StockMovement::record(
                    $this->ids->next(), $t, $key, $d, $qty, $return->businessDate, $return->returnedAt,
                    new SourceDocument('customer_return', $return->id(), $line->id), $return->id(), $return->actorUserId,
                    $line->reason ?? $return->reason, null, null, $lotId,
                );
                $out[] = $mk(MovementType::CUSTOMER_RETURN, Direction::IN);
                if ($line->disposition === ReturnDisposition::LOSS) {
                    $out[] = $mk(MovementType::LOSS, Direction::OUT);
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: Id, 1: Quantity}> */
    private function returnPortions(Id $saleLineId, Quantity $quantity): array
    {
        $left = $quantity;
        $out = [];
        foreach ($this->consumption->ofSaleLine($saleLineId) as $lot) {
            if (! $left->isPositive()) {
                break;
            }
            $open = Quantity::of(self::trim($lot['consumed']), $quantity->uom)->minus(Quantity::of(self::trim($lot['returned']), $quantity->uom));
            if (! $open->isPositive()) {
                continue;
            }
            $take = $open->isLessThan($left) ? $open : $left;
            $out[] = [Id::fromString($lot['lot_id']), $take];
            $left = $left->minus($take);
        }
        if ($left->isPositive()) {
            throw new LogicException("Sale line {$saleLineId->value}: lots do not cover the returned quantity.");
        }

        return $out;
    }

    /** "20.0000" → "20" (storage scale back to unit precision). */
    private static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
