<?php

declare(strict_types=1);

namespace App\Application\Shared\Audit;

use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Model\AggregateRoot;

/**
 * Convenience over AuditRecorder for aggregate changes: records only the
 * fields that actually changed (before → after) to keep audit rows readable.
 */
final class AuditTrail
{
    public function __construct(private readonly AuditRecorder $recorder)
    {
    }

    /** @param array<string, mixed> $context */
    public function created(Actor $actor, string $action, string $entityType, AggregateRoot $entity, array $context = []): void
    {
        $this->recorder->record(new AuditEntry($actor, $action, $entityType, $entity->id()->value, null, $entity->snapshot(), $context));
    }

    /**
     * @param  array<string, mixed>  $before  snapshot taken before the change
     * @param  array<string, mixed>  $context
     */
    public function changed(Actor $actor, string $action, string $entityType, AggregateRoot $entity, array $before, array $context = []): void
    {
        $after = $entity->snapshot();
        $changedKeys = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));

        $this->recorder->record(new AuditEntry(
            $actor,
            $action,
            $entityType,
            $entity->id()->value,
            array_intersect_key($before, array_flip($changedKeys)),
            array_intersect_key($after, array_flip($changedKeys)),
            $context,
        ));
    }
}
