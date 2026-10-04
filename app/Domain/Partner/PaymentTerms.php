<?php

declare(strict_types=1);

namespace App\Domain\Partner;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Money\Money;
use JsonSerializable;

/**
 * Commercial payment terms.
 *
 * - termDays = 0  → cash / COD (the default; most new customers start here)
 * - termDays > 0  → tempo of N days (max 365)
 * - creditLimit   → maximum outstanding receivable; null = no limit set.
 *   Per decision D6 the limit produces a warning in sales, not a hard block.
 */
final class PaymentTerms implements JsonSerializable
{
    public const MAX_TERM_DAYS = 365;

    private function __construct(
        public readonly int $termDays,
        public readonly ?Money $creditLimit,
    ) {
    }

    public static function cash(): self
    {
        return new self(0, null);
    }

    public static function of(int $termDays, ?Money $creditLimit = null): self
    {
        if ($termDays < 0 || $termDays > self::MAX_TERM_DAYS) {
            throw InvariantViolation::because('TERM_DAYS_INVALID', 'Payment term must be between 0 and '.self::MAX_TERM_DAYS.' days.', ['field' => 'term_days']);
        }
        $creditLimit?->assertNonNegative('Credit limit');

        return new self($termDays, $creditLimit);
    }

    public function isCash(): bool
    {
        return $this->termDays === 0;
    }

    public function equals(self $other): bool
    {
        if ($this->termDays !== $other->termDays) {
            return false;
        }
        if ($this->creditLimit === null || $other->creditLimit === null) {
            return $this->creditLimit === $other->creditLimit;
        }

        return $this->creditLimit->equals($other->creditLimit);
    }

    /** @return array{term_days: int, credit_limit: string|null} */
    public function jsonSerialize(): array
    {
        return ['term_days' => $this->termDays, 'credit_limit' => $this->creditLimit?->toStorage()];
    }
}
