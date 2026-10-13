<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Application\Identity\OperatorAccount;

/** JSON shape of an operator. Never includes credentials. */
final class OperatorResource
{
    /**
     * F5A: `permissions` = the sensitive permission values this operator's role
     * holds (additive field). The app uses it only to show or hide actions; the
     * server authorizes every command again.
     *
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    public static function make(OperatorAccount $operator, array $permissions = []): array
    {
        return [
            'id' => $operator->id,
            'name' => $operator->name,
            'email' => $operator->email,
            'role' => $operator->role->value,
            'is_active' => $operator->isActive,
            'permissions' => array_values($permissions),
        ];
    }
}
