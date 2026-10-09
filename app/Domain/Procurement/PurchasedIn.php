<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\UnitConversion;

/**
 * F4A: the purchasing terms of a PO line or a goods-receipt line exactly as
 * agreed / received — quantity and price in the purchasing unit plus the
 * conversion used. A snapshot: it is stored on the line and never re-derived
 * from the master conversion.
 *
 *   base quantity  = quantity × factor            (exact)
 *   base unit cost = unit price ÷ factor          (half-up, 4 decimals)
 *   total          = quantity × unit price        (half-up, 4 decimals; the agreed/actual amount)
 */
final class PurchasedIn
{
    public function __construct(
        public readonly UnitConversion $conversion,
        public readonly Quantity $quantity,
        public readonly Money $unitPrice,
    ) {
        $quantity->assertPositive('Quantity');
        $unitPrice->assertNonNegative('Unit price');
        $conversion->toBase($quantity); // proves the quantity is in the purchasing unit and converts exactly
    }

    /** The base-unit line (purchasing unit = base unit, factor 1). */
    public static function inBaseUnit(Quantity $quantity, Money $unitCost): self
    {
        return new self(UnitConversion::identity($quantity->uom), $quantity, $unitCost);
    }

    public function baseQuantity(): Quantity
    {
        return $this->conversion->toBase($this->quantity);
    }

    public function baseUnitCost(): Money
    {
        return $this->conversion->baseUnitCost($this->unitPrice);
    }

    public function total(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity->value());
    }
}
