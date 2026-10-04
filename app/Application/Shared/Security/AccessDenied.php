<?php

declare(strict_types=1);

namespace App\Application\Shared\Security;

use RuntimeException;

/** The actor is authenticated but lacks the permission (HTTP 403 ACCESS_DENIED). */
final class AccessDenied extends RuntimeException
{
    public const ERROR_CODE = 'ACCESS_DENIED';

    public function __construct(public readonly Permission $permission)
    {
        parent::__construct("Operator is not allowed to perform {$permission->value}.");
    }
}
