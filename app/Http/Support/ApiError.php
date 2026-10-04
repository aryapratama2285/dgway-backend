<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;

/**
 * Single error envelope for the whole API:
 *
 * { "error": { "code": "QUANTITY_NOT_POSITIVE", "message": "…", "details": {…} } }
 *
 * `code` is stable and meant for the client to branch on; `message` is for
 * humans and may change.
 */
final class ApiError
{
    /** @param array<string, mixed> $details */
    public static function response(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
