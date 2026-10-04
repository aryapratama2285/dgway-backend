<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Identity\LoginOperator;
use App\Application\Identity\LogoutOperator;
use App\Application\Identity\OperatorDirectory;
use App\Application\Shared\Security\ActorContext;
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
    public function login(LoginRequest $request, LoginOperator $login): JsonResponse
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
            'operator' => OperatorResource::make($result->operator),
        ]]);
    }

    public function logout(Request $request, LogoutOperator $logout, ActorContext $actors): JsonResponse
    {
        $tokenId = (string) $request->user()->currentAccessToken()->getKey();
        $logout->execute($actors->current(), $tokenId, RequestContext::from($request));

        return new JsonResponse(null, 204);
    }

    public function me(Request $request, OperatorDirectory $operators): JsonResponse
    {
        $operator = $operators->find((string) $request->user()->getAuthIdentifier());

        return $operator === null
            ? ApiError::response(404, 'NOT_FOUND', 'Operator tidak ditemukan.')
            : new JsonResponse(['data' => OperatorResource::make($operator)]);
    }
}
