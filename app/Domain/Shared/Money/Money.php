<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Math\Decimal;
use JsonSerializable;

/**
 * Immutable monetary amount.
 *
 * - Internal precision: 4 decimal places (matches NUMERIC(19,4) storage).
 * - Arithmetic between different currencies is forbidden.
 * - Rounding to whole Rupiah happens only at presentation / document-total
 *   boundaries via {@see roundedToCurrencyUnit()}, never silently.
 */
final class Money implements JsonSerializable
{
    public const SCALE = 4;

    private function __construct(
        private readonly Decimal $amount,
        public readonly Currency $currency,
    ) {
    }

    public static function of(string|int $amount, ?Currency $currency = null): self
    {
        $decimal = Decimal::of($amount);
        if ($decimal->significantScale() > self::SCALE) {
            throw InvariantViolation::because(
                'MONEY_PRECISION_EXCEEDED',
                'Money supports at most '.self::SCALE." decimal places, got '{$amount}'.",
            );
        }

        return new self($decimal->roundedTo(self::SCALE), $currency ?? Currency::idr());
    }

    public static function zero(?Currency $currency = null): self
    {
        return new self(Decimal::zero()->roundedTo(self::SCALE), $currency ?? Currency::idr());
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount)->roundedTo(self::SCALE), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount)->roundedTo(self::SCALE), $this->currency);
    }

    /**
     * Multiply by a plain factor (e.g. a quantity value or a ratio).
     * Result is rounded half-up to 4 decimals.
     */
    public function multipliedBy(string|int|Decimal $factor): self
    {
        return new self($this->amount->multipliedBy(Decimal::of($factor))->roundedTo(self::SCALE), $this->currency);
    }

    /** Divide by a plain factor; rounded half-up to 4 decimals. */
    public function dividedBy(string|int|Decimal $divisor): self
    {
        return new self($this->amount->dividedBy(Decimal::of($divisor), self::SCALE), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated()->roundedTo(self::SCALE), $this->currency);
    }

    /** Rounded to whole currency units (Rupiah has no minor unit in practice). */
    public function roundedToCurrencyUnit(): self
    {
        return new self($this->amount->roundedTo(0)->roundedTo(self::SCALE), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amount->isEqualTo($other->amount);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isLessThan($other->amount);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function assertNonNegative(string $label = 'Amount'): void
    {
        if ($this->isNegative()) {
            throw InvariantViolation::because('MONEY_NEGATIVE', "{$label} must not be negative.", ['amount' => $this->toStorage()]);
        }
    }

    public function amount(): Decimal
    {
        return $this->amount;
    }

    /** Fixed 4-decimal string suitable for a NUMERIC(19,4) column. */
    public function toStorage(): string
    {
        return $this->amount->toFixed(self::SCALE);
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toStorage(), 'currency' => $this->currency->code];
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw InvariantViolation::because(
                'CURRENCY_MISMATCH',
                "Cannot combine {$this->currency->code} with {$other->currency->code}.",
            );
        }
    }
}
