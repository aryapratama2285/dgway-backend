<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Application\Shared\Idempotency\IdempotentOutcome;
use Illuminate\Http\JsonResponse;

/**
 * Turns an idempotent command outcome into an HTTP response. A replayed
 * response carries `Idempotent-Replayed: true` so the client knows the
 * transaction already existed (it was not created twice).
 */
final class IdempotentResponse
{
    public static function from(IdempotentOutcome $outcome): JsonResponse
    {
        $response = new JsonResponse($outcome->result->body, $outcome->result->statusCode);
        $response->headers->set('Idempotent-Replayed', $outcome->replayed ? 'true' : 'false');

        return $response;
    }
}
