<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Quantity\UnitConversion;
use App\Domain\Shared\Quantity\Uom;

/**
 * F4A: a purchasing unit of one product — "1 [unit] = factor [base unit]"
 * (e.g. 1 TRAY = 15 KG of TELUR-STD). Master data: never deleted (deactivate
 * instead), identity (product, unit) immutable, versioned and audited.
 *
 * Documents copy the conversion when they are written (PO and goods-receipt
 * lines), so changing or deactivating it later never changes history.
 */
final class ProductUomConversion extends AggregateRoot
{
    private function __construct(
        Id $id,
        int $version,
        private readonly Id $productId,
        private UnitConversion $conversion,
        private bool $active,
    ) {
        parent::__construct($id, $version);
    }

    public static function create(Id $id, Id $productId, Uom $unit, Uom $baseUnit, string $factor): self
    {
        return new self($id, 0, $productId, self::convert($unit, $baseUnit, $factor), true);
    }

    public static function reconstitute(Id $id, int $version, Id $productId, Uom $unit, Uom $baseUnit, string $factor, bool $active): self
    {
        return new self($id, $version, $productId, UnitConversion::of($unit, $baseUnit, $factor), $active);
    }

    /** Returns true when anything changed. */
    public function set(string $factor, bool $active): bool
    {
        $next = self::convert($this->conversion->unit, $this->conversion->baseUnit, $factor);
        if ($next->equals($this->conversion) && $active === $this->active) {
            return false;
        }
        $this->conversion = $next;
        $this->active = $active;

        return true;
    }

    public function productId(): Id
    {
        return $this->productId;
    }

    public function conversion(): UnitConversion
    {
        return $this->conversion;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function snapshot(): array
    {
        return [
            'product_id' => $this->productId->value,
            'uom' => $this->conversion->unit->code,
            'base_uom' => $this->conversion->baseUnit->code,
            'factor_to_base' => $this->conversion->factorToStorage(),
            'is_active' => $this->active,
        ];
    }

    private static function convert(Uom $unit, Uom $baseUnit, string $factor): UnitConversion
    {
        if ($unit->equals($baseUnit)) {
            throw InvariantViolation::because('UOM_CONVERSION_INVALID',
                "{$unit->code} is already the base unit of this product; no conversion is needed.", ['field' => 'uom']);
        }

        return UnitConversion::of($unit, $baseUnit, $factor);
    }
}
