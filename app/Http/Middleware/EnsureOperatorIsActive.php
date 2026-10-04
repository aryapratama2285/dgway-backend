<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Support\ApiError;
use App\Infrastructure\Persistence\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic authorization for the MVP: the token must belong to an active
 * operator whose role may operate. A deactivated operator's existing tokens
 * stop working immediately.
 */
final class EnsureOperatorIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active || ! $user->role->canOperate()) {
            return ApiError::response(403, 'OPERATOR_INACTIVE', 'Akun operator tidak aktif atau tidak berwenang.');
        }

        Log::shareContext(['actor_user_id' => $user->id]);

        return $next($request);
    }
}
