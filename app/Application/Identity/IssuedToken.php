<?php

declare(strict_types=1);

namespace App\Application\Identity;

use DateTimeImmutable;

final class IssuedToken
{
    public function __construct(
        public readonly string $tokenId,
        /** Shown to the client exactly once; only its hash is stored. */
        public readonly string $plainTextToken,
        public readonly ?DateTimeImmutable $expiresAt,
    ) {
    }
}
