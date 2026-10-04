<?php

declare(strict_types=1);

namespace App\Infrastructure\Authentication;

use App\Application\Shared\Security\ActorContext;
use App\Domain\Shared\Identity\Actor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

final class LaravelActorContext implements ActorContext
{
    public function __construct(private readonly AuthFactory $auth)
    {
    }

    public function current(): Actor
    {
        $user = $this->auth->guard('sanctum')->user() ?? $this->auth->guard()->user();

        if ($user instanceof User) {
            return Actor::operator($user->id, $user->name);
        }

        return Actor::system();
    }
}
