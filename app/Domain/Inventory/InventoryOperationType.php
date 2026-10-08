<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** Stock operations performed directly on inventory (not via a receipt or sale). */
enum InventoryOperationType: string
{
    case LOSS = 'LOSS';                       // AVAILABLE → written off
    case REGRADE = 'REGRADE';                 // product A → product B (AVAILABLE)
    case RECOVERY = 'RECOVERY';               // RETURN_PENDING/QUARANTINE → AVAILABLE
    case SUPPLIER_RETURN = 'SUPPLIER_RETURN'; // RETURN_PENDING → shipped back to supplier
    case ADJUSTMENT = 'ADJUSTMENT';           // stock-count correction, IN or OUT, reason required
    case INTERNAL_CONSUMPTION = 'INTERNAL_CONSUMPTION'; // F3: DG Way used its own material (AVAILABLE → consumed)

    public function auditAction(): string
    {
        return match ($this) {
            self::LOSS => 'inventory.loss',
            self::REGRADE => 'inventory.regrade',
            self::RECOVERY => 'inventory.recovery',
            self::SUPPLIER_RETURN => 'inventory.return_to_supplier',
            self::ADJUSTMENT => 'inventory.adjustment',
            self::INTERNAL_CONSUMPTION => 'inventory.internal_consumption',
        };
    }
}
