<?php

declare(strict_types=1);

namespace App\Application\Shared\Security;

use App\Domain\Identity\Role;
use App\Domain\Shared\Identity\Actor;

/** Port: may this actor perform this permission-guarded action? */
interface Authorizer
{
    public function allows(Actor $actor, Permission $permission): bool;

    /** @throws AccessDenied */
    public function authorize(Actor $actor, Permission $permission): void;

    /**
     * F5A: like authorize(), but the system actor (server console, bootstrap,
     * maintenance commands) passes: it already requires shell access to the
     * server. Used where routine bootstrap code shares a use case with a
     * permission-guarded operator action (network setup, walk-in customer).
     *
     * @throws AccessDenied
     */
    public function authorizeIfOperator(Actor $actor, Permission $permission): void;

    /**
     * F5A: the permission values a role holds (every case for '*'), for the
     * client to show or hide actions. The server still decides on every call.
     *
     * @return list<string>
     */
    public function permissionsOf(Role $role): array;
}
