<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;

/**
 * The commercial terms a sale is confirmed under, captured from master data
 * at confirmation time (the Sales context does not import Partner classes).
 *
 * - anonymous walk-in (no customer)  → must pay in full, no receivable
 * - cash customer (term 0 days)      → must pay in full
 * - credit customer (term N days)    → may owe; due date = sale date + N;
 *                                      creditLimit null = no limit configured
 */
final class CustomerAccount
{
    private function __construct(
        public readonly ?Id $customerId,
        public readonly int $termDays,
        public readonly ?Money $creditLimit,
    ) {
    }

    public static function walkIn(): self
    {
        return new self(null, 0, null);
    }

    public static function customer(Id $customerId, int $termDays, ?Money $creditLimit): self
    {
        if ($termDays < 0) {
            throw InvariantViolation::because('TERM_DAYS_INVALID', 'Payment term cannot be negative.');
        }

        return new self($customerId, $termDays, $creditLimit);
    }

    public function isWalkIn(): bool
    {
        return $this->customerId === null;
    }

    public function mayOwe(): bool
    {
        return ! $this->isWalkIn() && $this->termDays > 0;
    }
}
