<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;

/** Identifies one stock position: product × node × bucket. */
final class StockKey
{
    public function __construct(
        public readonly Id $productId,
        public readonly Id $nodeId,
        public readonly StockBucket $bucket,
    ) {
    }

    /** Stable string form; also the lock order used to avoid deadlocks. */
    public function toString(): string
    {
        return $this->productId->value.'|'.$this->nodeId->value.'|'.$this->bucket->value;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
