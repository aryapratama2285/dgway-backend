<?php

declare(strict_types=1);

namespace App\Application\Identity;

final class LoginResult
{
    public function __construct(
        public readonly OperatorAccount $operator,
        public readonly IssuedToken $token,
    ) {
    }
}
