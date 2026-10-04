<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

/**
 * Outcome of trying to claim an idempotency key inside the current transaction.
 *
 * - fresh: this request owns the key and must execute the command.
 * - replay: the key was already used and committed; the stored result is returned.
 */
final class IdempotencyClaim
{
    private function __construct(
        public readonly string $recordId,
        public readonly bool $isReplay,
        public readonly string $requestHash,
        public readonly string $command,
        public readonly ?CommandResult $storedResult,
    ) {
    }

    public static function fresh(string $recordId, string $command, string $requestHash): self
    {
        return new self($recordId, false, $requestHash, $command, null);
    }

    public static function replay(string $recordId, string $command, string $requestHash, CommandResult $stored): self
    {
        return new self($recordId, true, $requestHash, $command, $stored);
    }
}
