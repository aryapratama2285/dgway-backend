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

    /** F5A: store a new password (hashed by the implementation). */
    public function changePassword(string $operatorId, string $plainPassword): void;

    /** F5A: account by email (console administration), or null. */
    public function findByEmail(string $email): ?OperatorAccount;

    /**
     * F5A: every account, active or not, for console listing.
     *
     * @return list<OperatorAccount>
     */
    public function all(): array;
}
