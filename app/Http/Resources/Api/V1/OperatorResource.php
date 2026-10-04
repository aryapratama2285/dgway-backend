<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Application\Identity\OperatorAccount;

/** JSON shape of an operator. Never includes credentials. */
final class OperatorResource
{
    /** @return array<string, mixed> */
    public static function make(OperatorAccount $operator): array
    {
        return [
            'id' => $operator->id,
            'name' => $operator->name,
            'email' => $operator->email,
            'role' => $operator->role->value,
            'is_active' => $operator->isActive,
        ];
    }
}
