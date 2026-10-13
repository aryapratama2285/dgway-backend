<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;

/**
 * F5A use case: an operator changes their own password from the app.
 *
 * - The current password must be given (a borrowed unlocked phone cannot take
 *   over the account). A wrong one is 422 CURRENT_PASSWORD_INVALID, never 401:
 *   a 401 would make the app treat the session as expired.
 * - Every OTHER token of the operator is revoked (other phones must sign in
 *   again); the token of the device making the change stays valid.
 * - Audited as operator.password_changed — without any password material.
 *
 * Resetting a forgotten password or another operator's password is NOT here:
 * it is done on the server console (dgway:operator:reset-password).
 */
final class ChangeOperatorPassword
{
    public function __construct(
        private readonly OperatorDirectory $directory,
        private readonly OperatorRegistry $registry,
        private readonly AccessTokenService $tokens,
        private readonly TransactionManager $transactions,
        private readonly AuditRecorder $audit,
        private readonly int $passwordMinLength,
    ) {
    }

    /**
     * @param  array<string, mixed>  $requestContext
     * @return int number of other device sessions revoked
     */
    public function execute(Actor $actor, string $currentTokenId, string $currentPassword, string $newPassword, array $requestContext = []): int
    {
        $account = $this->directory->find((string) $actor->userId)
            ?? throw InvariantViolation::because('CURRENT_PASSWORD_INVALID', 'Password saat ini salah.', ['field' => 'current_password']);

        if ($this->directory->verifyCredentials($account->email, $currentPassword) === null) {
            throw InvariantViolation::because('CURRENT_PASSWORD_INVALID', 'Password saat ini salah.', ['field' => 'current_password']);
        }
        if (mb_strlen($newPassword) < $this->passwordMinLength) {
            throw InvariantViolation::because('PASSWORD_TOO_SHORT', "Password baru minimal {$this->passwordMinLength} karakter.", ['field' => 'new_password', 'min_length' => $this->passwordMinLength]);
        }
        if ($newPassword === $currentPassword) {
            throw InvariantViolation::because('PASSWORD_UNCHANGED', 'Password baru harus berbeda dari password saat ini.', ['field' => 'new_password']);
        }

        return $this->transactions->run(function () use ($actor, $account, $currentTokenId, $newPassword, $requestContext): int {
            $this->registry->changePassword($account->id, $newPassword);
            $revoked = $this->tokens->revokeAllExcept($account->id, $currentTokenId);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'operator.password_changed',
                entityType: 'operator',
                entityId: $account->id,
                after: ['other_sessions_revoked' => $revoked, 'kept_token_id' => $currentTokenId],
                context: $requestContext,
            ));

            return $revoked;
        });
    }
}
