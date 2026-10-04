<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use RuntimeException;

/**
 * Base class for every business-rule violation raised by the domain layer.
 *
 * Each subclass carries a stable, machine-readable error code so the API and
 * the Flutter client can react to a specific rule rather than parse messages.
 */
abstract class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
