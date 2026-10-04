<?php

declare(strict_types=1);

namespace App\Application\Shared\Security;

use App\Domain\Shared\Identity\Actor;

/** Port: may this actor perform this permission-guarded action? */
interface Authorizer
{
    public function allows(Actor $actor, Permission $permission): bool;

    /** @throws AccessDenied */
    public function authorize(Actor $actor, Permission $permission): void;
}
