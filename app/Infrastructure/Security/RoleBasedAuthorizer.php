<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Identity\OperatorDirectory;
use App\Application\Shared\Security\AccessDenied;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Domain\Identity\Role;
use App\Domain\Shared\Identity\Actor;

/**
 * Role → permissions from config (dgway.authorization.role_permissions).
 * '*' grants every permission. The system actor (CLI, jobs) holds none of
 * these: permission-guarded actions require an accountable human operator.
 * An inactive or unknown operator holds nothing.
 */
final class RoleBasedAuthorizer implements Authorizer
{
    public function __construct(private readonly OperatorDirectory $operators)
    {
    }

    public function allows(Actor $actor, Permission $permission): bool
    {
        if ($actor->isSystem()) {
            return false;
        }
        $account = $this->operators->find((string) $actor->userId);
        if ($account === null || ! $account->isActive) {
            return false;
        }
        return in_array($permission->value, $this->permissionsOf($account->role), true);
    }

    public function authorize(Actor $actor, Permission $permission): void
    {
        $this->allows($actor, $permission) || throw new AccessDenied($permission);
    }

    public function authorizeIfOperator(Actor $actor, Permission $permission): void
    {
        if (! $actor->isSystem()) {
            $this->authorize($actor, $permission);
        }
    }

    public function permissionsOf(Role $role): array
    {
        $granted = (array) config("dgway.authorization.role_permissions.{$role->value}", []);
        $all = array_map(fn (Permission $p) => $p->value, Permission::cases());

        return in_array('*', $granted, true) ? $all : array_values(array_intersect($all, $granted));
    }
}
