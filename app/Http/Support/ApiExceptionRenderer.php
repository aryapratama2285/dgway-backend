<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Application\Identity\AuthenticationFailed;
use App\Application\Shared\Idempotency\IdempotencyKeyReused;
use App\Application\Shared\Security\AccessDenied;
use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Exception\ConflictsWithCurrentState;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Exception\DuplicateCode;
use App\Domain\Shared\Exception\EntityNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps exceptions to the API error envelope. Unknown exceptions never leak
 * internals (stack traces, SQL) outside debug mode.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiError::response(422, 'VALIDATION_FAILED', 'Data yang dikirim tidak valid.', ['fields' => $e->errors()]),
            // unknown scanned code: 404 with a fixed message that never echoes the code (A5-C)
            $e instanceof \App\Application\Printing\ScanRejected && $e->errorCode() === 'SCAN_CODE_UNKNOWN' => ApiError::response(404, $e->errorCode(), $e->getMessage()),
            $e instanceof EntityNotFound => ApiError::response(404, $e->errorCode(), $e->getMessage(), $e->context()),
            $e instanceof DuplicateCode, $e instanceof ConcurrentModification,
            // The request is valid but conflicts with the document's / stock's current state.
            $e instanceof ConflictsWithCurrentState => ApiError::response(409, $e->errorCode(), $e->getMessage(), $e->context()),
            $e instanceof DomainException => ApiError::response(422, $e->errorCode(), $e->getMessage(), $e->context()),
            $e instanceof AuthenticationFailed => ApiError::response($e->errorCode === AuthenticationFailed::OPERATOR_INACTIVE ? 403 : 401, $e->errorCode, $e->getMessage()),
            $e instanceof IdempotencyKeyReused => ApiError::response(409, IdempotencyKeyReused::ERROR_CODE, $e->getMessage()),
            $e instanceof AuthenticationException => ApiError::response(401, 'UNAUTHENTICATED', 'Silakan login terlebih dahulu.'),
            $e instanceof AccessDenied => ApiError::response(403, AccessDenied::ERROR_CODE, 'Anda tidak berwenang melakukan aksi ini.', ['permission' => $e->permission->value]),
            $e instanceof AuthorizationException => ApiError::response(403, 'FORBIDDEN', 'Anda tidak berwenang melakukan aksi ini.'),
            $e instanceof ThrottleRequestsException => ApiError::response(429, 'TOO_MANY_REQUESTS', 'Terlalu banyak percobaan. Coba lagi sebentar lagi.'),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiError::response(404, 'NOT_FOUND', 'Data tidak ditemukan.'),
            $e instanceof HttpExceptionInterface => ApiError::response($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Request error.'),
            default => self::internalError($e),
        };
    }

    /**
     * Unexpected failure. In production the response carries only a generic
     * message and a reference id; the exception (message, SQL, stack trace,
     * file paths) goes to the server log under that reference, never to the
     * client. Debug details are returned only when APP_DEBUG=true (local dev).
     */
    private static function internalError(Throwable $e): JsonResponse
    {
        $reference = (string) Str::uuid7();
        Log::error('Unhandled API exception', ['reference' => $reference, 'exception' => $e]);

        $details = ['reference' => $reference];
        if (config('app.debug') === true) {
            $details['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return ApiError::response(500, 'INTERNAL_ERROR', 'Terjadi kesalahan server. Transaksi tidak disimpan.', $details);
    }
}
