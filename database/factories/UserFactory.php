<?php

namespace Database\Factories;

use App\Domain\Identity\Role;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Test-only factory for operator accounts.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => 'Operator '.Str::upper(Str::random(4)),
            'email' => 'operator.'.Str::lower(Str::random(10)).'@dgway.test',
            'password' => static::$password ??= Hash::make('password-rahasia'),
            'role' => Role::OWNER,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
