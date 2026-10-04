<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;

/** Identifies one lot position: lot × node × bucket (product is implied by the lot). */
final class LotBalanceKey
{
    public function __construct(
        public readonly Id $lotId,
        public readonly StockKey $position,
    ) {
    }

    public static function of(StockMovement $m): self
    {
        return new self($m->lotId ?? throw new \LogicException('Movement has no lot.'), $m->key);
    }

    /** Stable string form; also the lock order (always taken after the position locks). */
    public function toString(): string
    {
        return $this->lotId->value.'|'.$this->position->nodeId->value.'|'.$this->position->bucket->value;
    }
}
