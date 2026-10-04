<?php

declare(strict_types=1);

namespace App\Application\Shared\Audit;

use App\Domain\Shared\Identity\Actor;

/**
 * One audit record: who did what to which entity, and what changed.
 *
 * `before` / `after` hold a compact snapshot of the business fields that
 * changed (not full rows). `context` holds request metadata such as the
 * idempotency key, IP address and client app version.
 */
final class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly Actor $actor,
        public readonly string $action,
        public readonly string $entityType,
        public readonly ?string $entityId = null,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly array $context = [],
    ) {
    }
}
