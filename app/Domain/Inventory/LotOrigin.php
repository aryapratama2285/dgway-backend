<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Why a lot exists. Quantity enters a lot only through the event that
 * created it (enforced in the database):
 *
 *   RECEIPT     a posted goods-receipt line (supplier + receipt known)   → RECEIPT movements
 *   REGRADE     a derived lot: part of a parent lot became another product → REGRADE_IN movement
 *   ADJUSTMENT  surplus found at a stock count (INV document with reason) → ADJUSTMENT_IN movement
 *   LEGACY      stock that existed before lot tracking (A3/A4 history);
 *               provenance deliberately unknown — never invented
 */
enum LotOrigin: string
{
    case RECEIPT = 'RECEIPT';
    case REGRADE = 'REGRADE';
    case ADJUSTMENT = 'ADJUSTMENT';
    case LEGACY = 'LEGACY';

    /** The movement type that creates quantity in a lot of this origin. */
    public function creatingMovement(): ?MovementType
    {
        return match ($this) {
            self::RECEIPT => MovementType::RECEIPT,
            self::REGRADE => MovementType::REGRADE_IN,
            self::ADJUSTMENT => MovementType::ADJUSTMENT_IN,
            self::LEGACY => null,
        };
    }
}
