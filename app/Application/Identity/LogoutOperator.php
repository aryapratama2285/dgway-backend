<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;

/** Use case: revoke the bearer token used by the current device. */
final class LogoutOperator
{
    public function __construct(
        private readonly AccessTokenService $tokens,
        private readonly TransactionManager $transactions,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** @param array<string, mixed> $requestContext */
    public function execute(Actor $actor, string $tokenId, array $requestContext = []): void
    {
        $this->transactions->run(function () use ($actor, $tokenId, $requestContext): void {
            $revoked = $this->tokens->revoke((string) $actor->userId, $tokenId);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'auth.logout',
                entityType: 'operator',
                entityId: $actor->userId,
                after: ['token_id' => $tokenId, 'revoked' => $revoked],
                context: $requestContext,
            ));
        });
    }
}
