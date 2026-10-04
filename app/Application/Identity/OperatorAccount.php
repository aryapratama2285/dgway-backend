<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Role;

/** Read model of an operator account, as seen by identity use cases. */
final class OperatorAccount
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly Role $role,
        public readonly bool $isActive,
    ) {
    }
}
