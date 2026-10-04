<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Quantity;

/** Quantity of one lot in one node/bucket: a projection of the ledger, changed only by movements. */
final class LotBalance
{
    private function __construct(
        public readonly LotBalanceKey $key,
        private Quantity $quantity,
    ) {
    }

    public static function of(LotBalanceKey $key, Quantity $quantity): self
    {
        return new self($key, $quantity);
    }

    public function apply(StockMovement $m, NegativeStockPolicy $policy): void
    {
        if (LotBalanceKey::of($m)->toString() !== $this->key->toString()) {
            throw InvariantViolation::because('MOVEMENT_POSITION_MISMATCH', 'Movement does not belong to this lot position.');
        }
        $next = $this->quantity->plus($m->signedQuantity());
        if ($next->isNegative() && ! $policy->allowsNegative($m->key)) {
            throw InsufficientStock::at($m->key, $this->quantity, $m->quantity, $this->key->lotId->value);
        }
        $this->quantity = $next;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }
}
