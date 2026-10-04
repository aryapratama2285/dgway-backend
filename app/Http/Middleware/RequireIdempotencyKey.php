<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Http\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to every final, state-changing business command (sale, payment,
 * goods receipt, QC, adjustment …). Rejects the request if the client did not
 * send a well-formed Idempotency-Key header.
 */
final class RequireIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';
    public const ATTRIBUTE = 'idempotency_key';

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->headers->get(self::HEADER);

        if ($raw === null || $raw === '') {
            return ApiError::response(428, 'IDEMPOTENCY_KEY_REQUIRED', 'Header Idempotency-Key wajib untuk transaksi ini.');
        }

        try {
            $request->attributes->set(self::ATTRIBUTE, $key = IdempotencyKey::fromString($raw));
            Log::shareContext(['idempotency_key' => $key->value]);
        } catch (InvalidArgumentException $e) {
            return ApiError::response(400, 'IDEMPOTENCY_KEY_INVALID', $e->getMessage());
        }

        return $next($request);
    }
}
