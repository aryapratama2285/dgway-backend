<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use App\Domain\Shared\Exception\InvariantViolation;

/**
 * ISO-4217 currency code. MVP operates only in IDR, but the type exists so that
 * every stored amount is explicit about its currency (Project B readiness).
 */
final class Currency
{
    private function __construct(public readonly string $code)
    {
    }

    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw InvariantViolation::because('CURRENCY_INVALID', "Invalid currency code '{$code}'.");
        }

        return new self($code);
    }

    public static function idr(): self
    {
        return new self('IDR');
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
