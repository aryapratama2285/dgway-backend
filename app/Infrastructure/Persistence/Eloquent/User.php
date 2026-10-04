<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Identity\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Persistence record for an operator account.
 *
 * This is an infrastructure class: it exists so Laravel's auth guard and
 * Sanctum can load the authenticated user. Business code talks to the
 * OperatorDirectory port, not to this model.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property bool $is_active
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $table = 'users';

    /** Mass assignment allow-list. */
    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
