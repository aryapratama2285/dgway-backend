<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * How money was received. All are recorded manually in A4 (QRIS included —
 * no gateway integration). Adding a method = a new case + extending the
 * database CHECK in a migration.
 */
enum PaymentMethod: string
{
    case CASH = 'CASH';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case QRIS = 'QRIS';
}
