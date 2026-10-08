<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Optional classification of a stock ADJUSTMENT (a correction, not a usage).
 * Damaged goods are NOT adjusted: they go through the LOSS operation.
 */
enum AdjustmentReason: string
{
    case COUNT_CORRECTION = 'COUNT_CORRECTION'; // stock opname found a difference
    case FOUND_STOCK = 'FOUND_STOCK';           // stock found that was never recorded
    case SHRINKAGE = 'SHRINKAGE';               // unexplained shortage (not damage)
    case OTHER = 'OTHER';
}
