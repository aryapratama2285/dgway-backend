<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Quantity;

/**
 * Current quantity of one stock position. A projection of the ledger:
 * it changes only by applying a StockMovement, never by being "set".
 */
final class StockBalance
{
    private function __construct(
        public readonly StockKey $key,
        private Quantity $quantity,
    ) {
    }

    public static function of(StockKey $key, Quantity $quantity): self
    {
        return new self($key, $quantity);
    }

    /** @throws InsufficientStock when the policy forbids going below zero */
    public function apply(StockMovement $movement, NegativeStockPolicy $policy): void
    {
        if (! $movement->key->equals($this->key)) {
            throw InvariantViolation::because('MOVEMENT_POSITION_MISMATCH', 'Movement does not belong to this stock position.');
        }
        if (! $movement->quantity->uom->equals($this->quantity->uom)) {
            throw InvariantViolation::because('UOM_MISMATCH', "Movement is in {$movement->quantity->uom->code}, position is in {$this->quantity->uom->code}.");
        }

        $next = $this->quantity->plus($movement->signedQuantity());
        if ($next->isNegative() && ! $policy->allowsNegative($this->key)) {
            throw InsufficientStock::at($this->key, $this->quantity, $movement->quantity);
        }
        $this->quantity = $next;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }
}
