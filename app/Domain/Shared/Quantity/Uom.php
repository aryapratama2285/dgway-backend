<?php

declare(strict_types=1);

namespace App\Domain\Shared\Quantity;

use App\Domain\Shared\Exception\InvariantViolation;

/**
 * Unit of measure as a value object: a code plus the number of decimals that
 * are physically meaningful for that unit.
 *
 * The set of units is NOT a closed enum: new units (TRAY, PETI, LITER, …) are
 * reference data (the `uoms` table). KG / PCS / IKAT are provided as
 * convenience factories because the MVP uses them from day one.
 *
 * Conversion between units is deliberately not part of this type. Conversions
 * belong to the Catalog (per product variant) and, for variable-weight goods
 * such as eggs, are not fixed at all (decision D1: eggs are stocked in KG).
 */
final class Uom
{
    public const MAX_PRECISION = 4;

    private function __construct(
        public readonly string $code,
        public readonly int $precision,
    ) {
    }

    public static function of(string $code, int $precision): self
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z][A-Z0-9_]{0,15}$/', $code) !== 1) {
            throw InvariantViolation::because('UOM_CODE_INVALID', "Invalid unit of measure code '{$code}'.");
        }
        if ($precision < 0 || $precision > self::MAX_PRECISION) {
            throw InvariantViolation::because(
                'UOM_PRECISION_INVALID',
                'UOM precision must be between 0 and '.self::MAX_PRECISION.'.',
            );
        }

        return new self($code, $precision);
    }

    /** Kilogram, gram-level precision. */
    public static function kg(): self
    {
        return new self('KG', 3);
    }

    /** Pieces, whole units only. */
    public static function pcs(): self
    {
        return new self('PCS', 0);
    }

    /** Bundle (e.g. bawang per ikat), whole units only. */
    public static function ikat(): self
    {
        return new self('IKAT', 0);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->precision === $other->precision;
    }
}
