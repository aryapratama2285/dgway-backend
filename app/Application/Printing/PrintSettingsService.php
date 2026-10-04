<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Identity\Actor;

/** Current print settings (stored or defaults) and the owner's update use case. */
final class PrintSettingsService
{
    public function __construct(
        private readonly PrintSettingsStore $store,
        private readonly PrintDefaults $defaults,
        private readonly TransactionManager $tx,
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function current(): PrintSettings
    {
        return $this->store->find() ?? $this->defaults->settings();
    }

    /** @param array<string, mixed> $changes only the keys present are changed; null clears an optional field */
    public function update(Actor $actor, array $changes, ?int $expectedVersion = null): PrintSettings
    {
        $this->authorizer->authorize($actor, Permission::MANAGE_PRINT_SETTINGS);

        return $this->tx->run(function () use ($actor, $changes, $expectedVersion): PrintSettings {
            $current = $this->store->findForUpdate() ?? $this->defaults->settings();
            if ($expectedVersion !== null && $expectedVersion !== $current->version) {
                throw ConcurrentModification::of('PrintSettings', $this->store->id() ?? 'default', $expectedVersion, $current->version);
            }
            $next = $current->with($changes);
            $this->store->save($next, $actor->userId);
            $this->audit->record(new AuditEntry($actor, 'print_settings.updated', 'print_settings', $this->store->id(), $current->toInput(), $next->toInput()));

            return $next;
        });
    }
}
