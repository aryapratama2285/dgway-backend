<?php

declare(strict_types=1);

namespace App\Application\Identity;

/** Port for issuing and revoking API bearer tokens. */
interface AccessTokenService
{
    public function issue(string $operatorId, string $deviceName): IssuedToken;

    /** Revokes one token of the operator. Returns false if it did not exist. */
    public function revoke(string $operatorId, string $tokenId): bool;
}
