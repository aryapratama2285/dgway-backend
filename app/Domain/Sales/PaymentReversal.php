<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/** The fact that a payment was wrong and is cancelled (its own append-only record). */
final class PaymentReversal
{
    public readonly string $reason;

    public function __construct(
        public readonly Id $id,
        string $reason,
        public readonly DateTimeImmutable $reversedAt,
        public readonly BusinessDate $businessDate,
        public readonly ?string $actorUserId,
    ) {
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('REVERSAL_REASON_REQUIRED', 'A reversal reason is required.', ['field' => 'reason']);
        }
        $this->reason = mb_substr($reason, 0, 500);
    }
}
