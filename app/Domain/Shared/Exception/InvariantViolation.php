<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * Raised when an operation would break a business invariant
 * (e.g. negative quantity, currency mismatch, unit mismatch).
 */
final class InvariantViolation extends DomainException
{
    /** @param array<string, mixed> $context */
    public static function because(string $code, string $message, array $context = []): self
    {
        return new self($message, $code, $context);
    }
}
