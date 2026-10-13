<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;
use InvalidArgumentException;

/**
 * F5A use case: operator administration from the server console (there is no
 * API for it on purpose). Deactivating or resetting a password revokes every
 * device token of that operator, so a lost phone or a leaver is cut off at once.
 * Every change is audited without password material.
 */
final class AdministerOperator
{
    public function __construct(
        private readonly OperatorRegistry $registry,
        private readonly AccessTokenService $tokens,
        private readonly TransactionManager $transactions,
        private readonly AuditRecorder $audit,
        private readonly int $passwordMinLength,
    ) {
    }

    /** @return int device sessions revoked */
    public function setActive(Actor $actor, string $email, bool $active): int
    {
        $account = $this->registry->findByEmail($email) ?? throw new InvalidArgumentException("No operator with email {$email}.");

        return $this->transactions->run(function () use ($actor, $account, $active): int {
            $this->registry->setActive($account->id, $active);
            $revoked = $active ? 0 : $this->tokens->revokeAllExcept($account->id, null);
            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: $active ? 'operator.activated' : 'operator.deactivated',
                entityType: 'operator',
                entityId: $account->id,
                before: ['is_active' => $account->isActive],
                after: ['is_active' => $active, 'sessions_revoked' => $revoked],
            ));

            return $revoked;
        });
    }

    /** @return int device sessions revoked */
    public function resetPassword(Actor $actor, string $email, string $plainPassword): int
    {
        $account = $this->registry->findByEmail($email) ?? throw new InvalidArgumentException("No operator with email {$email}.");
        if (mb_strlen($plainPassword) < $this->passwordMinLength) {
            throw new InvalidArgumentException("Password must be at least {$this->passwordMinLength} characters.");
        }

        return $this->transactions->run(function () use ($actor, $account, $plainPassword): int {
            $this->registry->changePassword($account->id, $plainPassword);
            $revoked = $this->tokens->revokeAllExcept($account->id, null);
            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'operator.password_reset',
                entityType: 'operator',
                entityId: $account->id,
                after: ['sessions_revoked' => $revoked],
            ));

            return $revoked;
        });
    }
}
