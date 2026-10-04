<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/** A lot that currently holds stock in a position — a candidate for allocation. */
final class LotStock
{
    public function __construct(
        public readonly Id $lotId,
        public readonly string $lotNumber,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $receivedAt,
        public readonly Quantity $quantity,
    ) {
    }
}
