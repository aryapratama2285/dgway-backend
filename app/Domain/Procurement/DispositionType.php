<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

/**
 * What happens to physically received quantity.
 *
 * ACCEPT              → AVAILABLE stock of the received product
 * QUARANTINE          → held in QUARANTINE (F4A): received and owned, not yet
 *                       sellable; released later by the RECOVERY operation or
 *                       written off as a LOSS — never silently
 * LOSS                → written off (no recoverable value); traced in the ledger
 * RETURN_TO_SUPPLIER  → held in RETURN_PENDING (not sellable, not a loss)
 *                       until shipped back or recovered
 * REGRADE             → becomes AVAILABLE stock of another product/grade
 *                       (e.g. TELUR-STD → TELUR-AFKIR); quantity conserved
 * RECOVERY            → not a receiving decision: it applies to stock that is
 *                       already non-sellable (inventory operation). Declared
 *                       here so the vocabulary is shared.
 */
enum DispositionType: string
{
    case ACCEPT = 'ACCEPT';
    case QUARANTINE = 'QUARANTINE';
    case LOSS = 'LOSS';
    case RETURN_TO_SUPPLIER = 'RETURN_TO_SUPPLIER';
    case REGRADE = 'REGRADE';
    case RECOVERY = 'RECOVERY';

    public function appliesAtReceipt(): bool
    {
        return $this !== self::RECOVERY;
    }

    public function requiresTargetProduct(): bool
    {
        return $this === self::REGRADE;
    }
}
