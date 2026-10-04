<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;

/**
 * Use case: an operator signs in from a device and receives a bearer token.
 *
 * Successful and failed attempts are both audited. A failed attempt is
 * committed in its own transaction before the error is raised, so it is never
 * lost to a rollback.
 */
final class LoginOperator
{
    public function __construct(
        private readonly OperatorDirectory $operators,
        private readonly AccessTokenService $tokens,
        private readonly TransactionManager $transactions,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** @param array<string, mixed> $requestContext */
    public function execute(string $email, string $password, string $deviceName, array $requestContext = []): LoginResult
    {
        $account = $this->operators->verifyCredentials($email, $password);

        if ($account === null) {
            $this->recordFailure('auth.login_failed', ['email' => mb_strtolower($email), 'reason' => AuthenticationFailed::INVALID_CREDENTIALS], $requestContext);
            throw AuthenticationFailed::invalidCredentials();
        }

        if (! $account->isActive) {
            $this->recordFailure('auth.login_failed', ['email' => $account->email, 'reason' => AuthenticationFailed::OPERATOR_INACTIVE], $requestContext);
            throw AuthenticationFailed::inactive();
        }

        return $this->transactions->run(function () use ($account, $deviceName, $requestContext): LoginResult {
            $token = $this->tokens->issue($account->id, $deviceName);
            $this->operators->markLoggedIn($account->id);

            $this->audit->record(new AuditEntry(
                actor: Actor::operator($account->id, $account->name),
                action: 'auth.login',
                entityType: 'operator',
                entityId: $account->id,
                after: ['device_name' => $deviceName, 'token_id' => $token->tokenId],
                context: $requestContext,
            ));

            return new LoginResult($account, $token);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $requestContext
     */
    private function recordFailure(string $action, array $details, array $requestContext): void
    {
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            actor: Actor::system('auth'),
            action: $action,
            entityType: 'operator',
            after: $details,
            context: $requestContext,
        )));
    }
}
