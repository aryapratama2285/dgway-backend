<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

use InvalidArgumentException;

/**
 * Client-generated key that makes a state-changing request safe to retry.
 *
 * The Flutter app generates one UUID per user action (e.g. one "Simpan
 * Penjualan" tap) and re-sends the SAME key on every retry of that action.
 */
final class IdempotencyKey
{
    private function __construct(public readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{15,127}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'Idempotency-Key must be 16–128 characters of letters, digits, "-" or "_" (a UUID is recommended).',
            );
        }

        return new self($value);
    }
}
