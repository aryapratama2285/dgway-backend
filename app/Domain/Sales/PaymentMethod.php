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
    /** F4B: any other manual settlement; it must be described (reference or notes). */
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /** An OTHER payment is never anonymous: its reference or notes must say what it was. */
    public function requiresDescription(): bool
    {
        return $this === self::OTHER;
    }
}
