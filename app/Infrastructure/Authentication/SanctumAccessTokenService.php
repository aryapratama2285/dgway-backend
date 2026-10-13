<?php

declare(strict_types=1);

namespace App\Infrastructure\Authentication;

use App\Application\Identity\AccessTokenService;
use App\Application\Identity\IssuedToken;
use App\Domain\Shared\Time\Clock;
use App\Infrastructure\Persistence\Eloquent\User;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Bearer tokens via Laravel Sanctum. Only a SHA-256 hash of the token is
 * stored; the plain-text token is returned to the client once.
 */
final class SanctumAccessTokenService implements AccessTokenService
{
    public const ABILITY_OPERATE = 'operate';

    public function __construct(
        private readonly Clock $clock,
        private readonly ?int $ttlDays,
    ) {
    }

    public function issue(string $operatorId, string $deviceName): IssuedToken
    {
        $user = User::query()->find($operatorId) ?? throw new RuntimeException('Operator not found.');
        $expiresAt = $this->ttlDays ? $this->clock->now()->modify("+{$this->ttlDays} days") : null;

        $new = $user->createToken($deviceName, [self::ABILITY_OPERATE], $expiresAt);

        return new IssuedToken((string) $new->accessToken->getKey(), $new->plainTextToken, $expiresAt);
    }

    public function revoke(string $operatorId, string $tokenId): bool
    {
        return PersonalAccessToken::query()
            ->whereKey($tokenId)
            ->where('tokenable_type', (new User())->getMorphClass())
            ->where('tokenable_id', $operatorId)
            ->delete() > 0;
    }

    public function revokeAllExcept(string $operatorId, ?string $keepTokenId): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->where('tokenable_id', $operatorId)
            ->when($keepTokenId !== null, fn ($q) => $q->whereKeyNot($keepTokenId))
            ->delete();
    }
}
