<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/** What the operator says was paid; the Sale turns it into a Payment if allowed. */
final class PaymentRequest
{
    public function __construct(
        public readonly Id $id,
        public readonly DocumentNumber $number,
        public readonly PaymentMethod $method,
        public readonly Money $amount,
        public readonly DateTimeImmutable $paidAt,
        public readonly BusinessDate $businessDate,
        public readonly ?string $actorUserId,
        public readonly ?string $reference = null,
        public readonly ?string $notes = null,
    ) {
    }
}
