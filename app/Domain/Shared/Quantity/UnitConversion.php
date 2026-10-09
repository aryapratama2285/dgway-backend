<?php

declare(strict_types=1);

namespace App\Domain\Shared\Quantity;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Math\Decimal;
use App\Domain\Shared\Money\Money;

/**
 * F4A: how many BASE units one unit of another (purchasing) unit holds, for one
 * product — e.g. 1 TRAY = 15.000000 KG. A value object: documents keep a copy
 * (snapshot) of the conversion they were written with, so a later change of
 * the master conversion never changes what an old document means.
 *
 * - Quantities convert EXACTLY: a purchasing quantity whose base equivalent
 *   needs more decimals than the base unit allows is refused, never rounded.
 * - Prices convert to a base-unit cost by division, rounded half-up to 4
 *   decimals (Money precision) — the only rounding in the conversion.
 */
final class UnitConversion
{
    public const FACTOR_SCALE = 6;

    private function __construct(
        public readonly Uom $unit,
        public readonly Uom $baseUnit,
        private readonly Decimal $factor,
    ) {
    }

    public static function identity(Uom $baseUnit): self
    {
        return new self($baseUnit, $baseUnit, Decimal::of(1));
    }

    public static function of(Uom $unit, Uom $baseUnit, string|int|Decimal $factor): self
    {
        $f = Decimal::of($factor);
        if (! $f->isPositive() || $f->significantScale() > self::FACTOR_SCALE) {
            throw InvariantViolation::because('UOM_CONVERSION_INVALID',
                "The conversion factor must be greater than zero with at most ".self::FACTOR_SCALE." decimals, got '{$f}'.", ['field' => 'factor']);
        }
        if ($unit->equals($baseUnit)) {
            if (! $f->isEqualTo(Decimal::of(1))) {
                throw InvariantViolation::because('UOM_CONVERSION_INVALID', "{$unit->code} is the base unit; its factor is always 1.", ['field' => 'uom']);
            }

            return self::identity($baseUnit);
        }

        return new self($unit, $baseUnit, $f->roundedTo(self::FACTOR_SCALE));
    }

    public function isIdentity(): bool
    {
        return $this->unit->equals($this->baseUnit);
    }

    public function factor(): Decimal
    {
        return $this->factor;
    }

    public function factorToStorage(): string
    {
        return $this->factor->toFixed(self::FACTOR_SCALE);
    }

    /** Exact conversion of a quantity in [$unit] to the base unit. */
    public function toBase(Quantity $quantity, string $field = 'quantity'): Quantity
    {
        if (! $quantity->uom->equals($this->unit)) {
            throw InvariantViolation::because('UOM_MISMATCH', "Expected a quantity in {$this->unit->code}, got {$quantity->uom->code}.", ['field' => $field]);
        }
        if ($this->isIdentity()) {
            return $quantity;
        }
        $base = $quantity->value()->multipliedBy($this->factor);
        if ($base->significantScale() > $this->baseUnit->precision) {
            throw InvariantViolation::because('UOM_CONVERSION_INVALID',
                "{$quantity->toStorage()} {$this->unit->code} is {$base} {$this->baseUnit->code}, which needs more than {$this->baseUnit->precision} decimal(s).",
                ['field' => $field, 'base_uom' => $this->baseUnit->code]);
        }

        return Quantity::of($base->toFixed(Quantity::SCALE), $this->baseUnit);
    }

    /** Cost of one base unit for a price per [$unit]: price ÷ factor, half-up to 4 decimals. */
    public function baseUnitCost(Money $pricePerUnit): Money
    {
        return $this->isIdentity() ? $pricePerUnit : $pricePerUnit->dividedBy($this->factor);
    }

    public function equals(self $other): bool
    {
        return $this->unit->equals($other->unit) && $this->baseUnit->equals($other->baseUnit) && $this->factor->isEqualTo($other->factor);
    }
}
