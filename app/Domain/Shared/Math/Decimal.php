<?php

declare(strict_types=1);

namespace App\Domain\Shared\Math;

use App\Domain\Shared\Exception\InvariantViolation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Thin, immutable wrapper around arbitrary-precision decimals.
 *
 * Business numbers (money, quantity, prices, yields) never touch PHP floats.
 * This wrapper is the single place that knows about the math library, so the
 * rest of the domain is insulated from its API.
 */
final class Decimal
{
    private function __construct(private readonly BigDecimal $value)
    {
    }

    /**
     * Accepts only string or int. Floats are rejected on purpose: a float has
     * already lost precision before we ever see it.
     */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
                throw InvariantViolation::because('DECIMAL_INVALID', "'{$value}' is not a valid decimal number.");
            }
        }

        try {
            return new self(BigDecimal::of($value));
        } catch (MathException $e) {
            throw InvariantViolation::because('DECIMAL_INVALID', "'{$value}' is not a valid decimal number.");
        }
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function multipliedBy(self $other): self
    {
        return new self($this->value->multipliedBy($other->value));
    }

    /** Division always needs an explicit scale; rounding is half-up. */
    public function dividedBy(self $other, int $scale): self
    {
        if ($other->isZero()) {
            throw InvariantViolation::because('DIVISION_BY_ZERO', 'Cannot divide by zero.');
        }

        return new self($this->value->dividedBy($other->value, $scale, RoundingMode::HalfUp));
    }

    /** Rounds half-up to the given number of decimal places. */
    public function roundedTo(int $scale): self
    {
        return new self($this->value->toScale($scale, RoundingMode::HalfUp));
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    /** Number of digits after the decimal point once trailing zeros are removed. */
    public function significantScale(): int
    {
        return $this->value->strippedOfTrailingZeros()->getScale();
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isEqualTo(self $other): bool
    {
        return $this->value->isEqualTo($other->value);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value->isGreaterThan($other->value);
    }

    public function isLessThan(self $other): bool
    {
        return $this->value->isLessThan($other->value);
    }

    /** Fixed-scale string, e.g. for NUMERIC(19,4) storage. */
    public function toFixed(int $scale): string
    {
        return (string) $this->value->toScale($scale, RoundingMode::HalfUp);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
