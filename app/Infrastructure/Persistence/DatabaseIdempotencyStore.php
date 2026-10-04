<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotencyClaim;
use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Application\Shared\Idempotency\IdempotencyStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * PostgreSQL implementation of the idempotency store.
 *
 * claim() uses INSERT … ON CONFLICT DO NOTHING on the unique
 * (actor_scope, idempotency_key) index. If a concurrent transaction holds the
 * same key, PostgreSQL makes this INSERT wait for it; afterwards we either
 * own a fresh row or read the committed row FOR UPDATE and replay it.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    private const SYSTEM_SCOPE = 'system';

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function claim(?string $actorUserId, IdempotencyKey $key, string $command, string $requestHash): IdempotencyClaim
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Idempotency keys must be claimed inside a transaction.');
        }

        $scope = $actorUserId ?? self::SYSTEM_SCOPE;
        $id = (string) Str::uuid7();

        $inserted = $this->connection->selectOne(
            <<<'SQL'
                INSERT INTO idempotency_keys (id, actor_scope, actor_user_id, idempotency_key, command, request_hash, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', now())
                ON CONFLICT (actor_scope, idempotency_key) DO NOTHING
                RETURNING id
                SQL,
            [$id, $scope, $actorUserId, $key->value, $command, $requestHash],
        );

        if ($inserted !== null) {
            return IdempotencyClaim::fresh($id, $command, $requestHash);
        }

        $existing = $this->connection->selectOne(
            'SELECT id, command, request_hash, status, response_status, response_body, resource_type, resource_id
               FROM idempotency_keys WHERE actor_scope = ? AND idempotency_key = ? FOR UPDATE',
            [$scope, $key->value],
        );

        $stored = new CommandResult(
            statusCode: (int) $existing->response_status,
            body: json_decode((string) $existing->response_body, true, 512, JSON_THROW_ON_ERROR),
            resourceType: $existing->resource_type,
            resourceId: $existing->resource_id,
        );

        return IdempotencyClaim::replay($existing->id, $existing->command, $existing->request_hash, $stored);
    }

    public function complete(string $recordId, CommandResult $result): void
    {
        $this->connection->table('idempotency_keys')->where('id', $recordId)->update([
            'status' => 'COMPLETED',
            'response_status' => $result->statusCode,
            'response_body' => json_encode((object) $result->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'resource_type' => $result->resourceType,
            'resource_id' => $result->resourceId,
            'completed_at' => $this->connection->raw('now()'),
        ]);
    }
}
