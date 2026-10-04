<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditScope;
use App\Domain\Shared\Time\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Writes audit entries into `audit_logs` on the caller's connection, so they
 * share the caller's transaction. The table rejects UPDATE/DELETE via a
 * database trigger (append-only).
 */
final class DatabaseAuditRecorder implements AuditRecorder
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
        private readonly AuditScope $scope,
    ) {
    }

    public function record(AuditEntry $entry): string
    {
        $id = (string) Str::uuid7();
        $now = $this->clock->now();

        $this->connection->table('audit_logs')->insert([
            'id' => $id,
            'occurred_at' => $now->format('Y-m-d H:i:s.uP'),
            'business_date' => $this->clock->today()->toString(),
            'actor_user_id' => $entry->actor->userId,
            'actor_label' => $entry->actor->label,
            'action' => $entry->action,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'before' => $entry->before === null ? null : json_encode($entry->before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after' => $entry->after === null ? null : json_encode($entry->after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'context' => json_encode((object) ($entry->context + $this->scope->current()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $id;
    }
}
