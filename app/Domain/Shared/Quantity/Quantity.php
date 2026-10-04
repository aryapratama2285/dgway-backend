<?php

declare(strict_types=1);

namespace App\Domain\Shared\Quantity;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Math\Decimal;
use JsonSerializable;

/**
 * Immutable business quantity: a decimal value bound to a unit of measure.
 *
 * - Storage precision is 4 decimals (NUMERIC(18,4)).
 * - The value may not carry more decimals than its unit allows
 *   (1.5 PCS is rejected, 35.125 KG is accepted).
 * - Quantities in different units are never combined implicitly.
 * - A quantity may be negative (e.g. a stock-count variance); callers that
 *   need strictly positive amounts use {@see assertPositive()}.
 */
final class Quantity implements JsonSerializable
{
    public const SCALE = 4;

    private function __construct(
        private readonly Decimal $value,
        public readonly Uom $uom,
    ) {
    }

    public static function of(string|int $value, Uom $uom): self
    {
        $decimal = Decimal::of($value);
        if ($decimal->significantScale() > $uom->precision) {
            throw InvariantViolation::because(
                'QUANTITY_PRECISION_EXCEEDED',
                "Unit {$uom->code} allows {$uom->precision} decimal place(s), got '{$value}'.",
                ['uom' => $uom->code, 'value' => (string) $value],
            );
        }

        return new self($decimal->roundedTo(self::SCALE), $uom);
    }

    public static function zero(Uom $uom): self
    {
        return new self(Decimal::zero()->roundedTo(self::SCALE), $uom);
    }

    public function plus(self $other): self
    {
        $this->assertSameUom($other);

        return new self($this->value->plus($other->value)->roundedTo(self::SCALE), $this->uom);
    }

    public function minus(self $other): self
    {
        $this->assertSameUom($other);

        return new self($this->value->minus($other->value)->roundedTo(self::SCALE), $this->uom);
    }

    public function negated(): self
    {
        return new self($this->value->negated()->roundedTo(self::SCALE), $this->uom);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->uom->equals($other->uom) && $this->value->isEqualTo($other->value);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameUom($other);

        return $this->value->isGreaterThan($other->value);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameUom($other);

        return $this->value->isLessThan($other->value);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameUom($other);

        return $this->value->compareTo($other->value);
    }

    public function assertPositive(string $label = 'Quantity'): void
    {
        if (! $this->isPositive()) {
            throw InvariantViolation::because(
                'QUANTITY_NOT_POSITIVE',
                "{$label} must be greater than zero.",
                ['value' => $this->toStorage(), 'uom' => $this->uom->code],
            );
        }
    }

    public function assertNonNegative(string $label = 'Quantity'): void
    {
        if ($this->isNegative()) {
            throw InvariantViolation::because(
                'QUANTITY_NEGATIVE',
                "{$label} must not be negative.",
                ['value' => $this->toStorage(), 'uom' => $this->uom->code],
            );
        }
    }

    public function value(): Decimal
    {
        return $this->value;
    }

    /** Fixed 4-decimal string suitable for a NUMERIC(18,4) column. */
    public function toStorage(): string
    {
        return $this->value->toFixed(self::SCALE);
    }

    /** @return array{value: string, uom: string} */
    public function jsonSerialize(): array
    {
        return ['value' => $this->toStorage(), 'uom' => $this->uom->code];
    }

    private function assertSameUom(self $other): void
    {
        if (! $this->uom->equals($other->uom)) {
            throw InvariantViolation::because(
                'UOM_MISMATCH',
                "Cannot combine {$this->uom->code} with {$other->uom->code} without an explicit conversion.",
            );
        }
    }
}
