<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Role;

/** Port for creating and (de)activating operator accounts. */
interface OperatorRegistry
{
    public function emailExists(string $email): bool;

    public function create(string $name, string $email, string $plainPassword, Role $role): OperatorAccount;

    public function setActive(string $operatorId, bool $active): void;
}
