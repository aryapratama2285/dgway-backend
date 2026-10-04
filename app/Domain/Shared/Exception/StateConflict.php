<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class StateConflict extends DomainException implements ConflictsWithCurrentState
{
    /** @param array<string, mixed> $context */
    public static function because(string $code, string $message, array $context = []): self
    {
        return new self($message, $code, $context);
    }
}
