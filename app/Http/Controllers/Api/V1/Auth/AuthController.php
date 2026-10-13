<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Identity\ChangeOperatorPassword;
use App\Application\Identity\LoginOperator;
use App\Application\Identity\LogoutOperator;
use App\Application\Identity\OperatorDirectory;
use App\Application\Shared\Security\ActorContext;
use App\Application\Shared\Security\Authorizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\OperatorResource;
use App\Http\Support\ApiError;
use App\Http\Support\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin HTTP adapter: validate → call use case → shape JSON. */
final class AuthController extends Controller
{
    public function login(LoginRequest $request, LoginOperator $login, Authorizer $authorizer): JsonResponse
    {
        $result = $login->execute(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) $request->validated('device_name'),
            RequestContext::from($request),
        );

        return new JsonResponse(['data' => [
            'token' => $result->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result->token->expiresAt?->format(DATE_ATOM),
            'operator' => OperatorResource::make($result->operator, $authorizer->permissionsOf($result->operator->role)),
        ]]);
    }

    public function logout(Request $request, LogoutOperator $logout, ActorContext $actors): JsonResponse
    {
        $tokenId = (string) $request->user()->currentAccessToken()->getKey();
        $logout->execute($actors->current(), $tokenId, RequestContext::from($request));

        return new JsonResponse(null, 204);
    }

    public function me(Request $request, OperatorDirectory $operators, Authorizer $authorizer): JsonResponse
    {
        $operator = $operators->find((string) $request->user()->getAuthIdentifier());

        return $operator === null
            ? ApiError::response(404, 'NOT_FOUND', 'Operator tidak ditemukan.')
            : new JsonResponse(['data' => OperatorResource::make($operator, $authorizer->permissionsOf($operator->role))]);
    }

    /**
     * F5A: POST /auth/password {current_password, new_password}.
     * Not under the idempotency middleware on purpose: the request body holds
     * passwords, and the idempotency store keeps a hash of every command body.
     * A retry after a lost answer fails with CURRENT_PASSWORD_INVALID, which
     * the app explains ("if it was changed, use the new password").
     */
    public function changePassword(Request $request, ChangeOperatorPassword $change, ActorContext $actors): JsonResponse
    {
        $v = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'new_password' => ['required', 'string', 'max:200'],
        ]);
        $tokenId = (string) $request->user()->currentAccessToken()->getKey();
        $revoked = $change->execute($actors->current(), $tokenId, (string) $v['current_password'], (string) $v['new_password'], RequestContext::from($request));

        return new JsonResponse(['data' => ['password_changed' => true, 'other_sessions_revoked' => $revoked]]);
    }
}
