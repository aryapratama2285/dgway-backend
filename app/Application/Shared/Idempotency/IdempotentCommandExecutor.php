<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

use App\Application\Shared\Audit\AuditScope;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;

/**
 * Runs a state-changing command at most once per (actor, idempotency key).
 *
 * Flow, all inside ONE database transaction:
 *   1. claim the key (blocks if a concurrent request holds it)
 *   2. replay  → return the stored result, command is NOT executed again
 *      fresh   → execute the command
 *   3. store the command's result against the key
 *   4. commit (or roll back everything, including the claim)
 *
 * This is what lets the Flutter app retry after a network drop without ever
 * creating a duplicate sale, payment or receipt.
 */
final class IdempotentCommandExecutor
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly IdempotencyStore $store,
        private readonly AuditScope $auditScope = new AuditScope(),
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  the request payload that defines "the same request"
     * @param  callable(): CommandResult  $command
     */
    public function execute(Actor $actor, IdempotencyKey $key, string $commandName, array $payload, callable $command): IdempotentOutcome
    {
        $hash = self::hashRequest($commandName, $payload);

        return $this->transactions->run(function () use ($actor, $key, $commandName, $hash, $command): IdempotentOutcome {
            $claim = $this->store->claim($actor->userId, $key, $commandName, $hash);

            if ($claim->isReplay) {
                if ($claim->requestHash !== $hash || $claim->command !== $commandName || $claim->storedResult === null) {
                    throw new IdempotencyKeyReused($key->value);
                }

                return new IdempotentOutcome($claim->storedResult, true);
            }

            // every audit entry of this command can be traced back to the request
            $result = $this->auditScope->within(['idempotency_key' => $key->value, 'command' => $commandName], $command);
            $this->store->complete($claim->recordId, $result);

            return new IdempotentOutcome($result, false);
        });
    }

    /** @param array<string, mixed> $payload */
    public static function hashRequest(string $commandName, array $payload): string
    {
        return hash('sha256', $commandName."\n".json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonicalize(...), $value);
    }
}
