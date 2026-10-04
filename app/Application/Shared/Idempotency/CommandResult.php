<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

/**
 * The response of a state-changing command, stored so that a retry with the
 * same idempotency key gets exactly the same answer without re-executing.
 */
final class CommandResult
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $body,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null,
    ) {
    }
}
