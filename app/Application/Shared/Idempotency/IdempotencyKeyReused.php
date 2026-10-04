<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

use RuntimeException;

/**
 * The same idempotency key was sent with a different command or payload.
 * This is a client bug (key reuse across different actions) and is rejected
 * rather than guessed at.
 */
final class IdempotencyKeyReused extends RuntimeException
{
    public const ERROR_CODE = 'IDEMPOTENCY_KEY_REUSED';

    public function __construct(public readonly string $key)
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }
}
