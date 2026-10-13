<?php

declare(strict_types=1);

namespace App\Infrastructure\Authentication;

use App\Application\Identity\OperatorAccount;
use App\Application\Identity\OperatorDirectory;
use App\Application\Identity\OperatorRegistry;
use App\Domain\Identity\Role;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Contracts\Hashing\Hasher;

final class EloquentOperatorDirectory implements OperatorDirectory, OperatorRegistry
{
    /** A valid hash used to equalise timing when the email does not exist. */
    private ?string $dummyHash = null;

    public function __construct(private readonly Hasher $hasher)
    {
    }

    public function verifyCredentials(string $email, string $password): ?OperatorAccount
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user === null) {
            $this->hasher->check($password, $this->dummyHash ??= $this->hasher->make('dgway-timing-equaliser'));

            return null;
        }

        if (! $this->hasher->check($password, $user->password)) {
            return null;
        }

        return self::toAccount($user);
    }

    public function find(string $operatorId): ?OperatorAccount
    {
        $user = User::query()->find($operatorId);

        return $user === null ? null : self::toAccount($user);
    }

    public function markLoggedIn(string $operatorId): void
    {
        User::query()->whereKey($operatorId)->update(['last_login_at' => now('UTC')]);
    }

    public function emailExists(string $email): bool
    {
        return User::query()->where('email', mb_strtolower(trim($email)))->exists();
    }

    public function create(string $name, string $email, string $plainPassword, Role $role): OperatorAccount
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => $plainPassword, // hashed by the model's 'hashed' cast
            'role' => $role,
            'is_active' => true,
        ]);

        return self::toAccount($user);
    }

    public function setActive(string $operatorId, bool $active): void
    {
        User::query()->whereKey($operatorId)->update(['is_active' => $active]);
    }

    public function changePassword(string $operatorId, string $plainPassword): void
    {
        $user = User::query()->findOrFail($operatorId);
        $user->password = $plainPassword; // hashed by the model's 'hashed' cast
        $user->save();
    }

    public function findByEmail(string $email): ?OperatorAccount
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $user === null ? null : self::toAccount($user);
    }

    public function all(): array
    {
        return User::query()->orderBy('email')->get()->map(fn (User $u) => self::toAccount($u))->values()->all();
    }

    private static function toAccount(User $user): OperatorAccount
    {
        return new OperatorAccount(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->role,
            isActive: $user->is_active,
        );
    }
}
