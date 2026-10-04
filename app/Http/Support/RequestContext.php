<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Http\Request;

/** Request metadata attached to audit entries. */
final class RequestContext
{
    /** @return array<string, mixed> */
    public static function from(Request $request): array
    {
        $key = $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE);

        return array_filter([
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'app_version' => $request->headers->get('X-App-Version'),
            'idempotency_key' => $key?->value,
            'endpoint' => $request->method().' '.$request->path(),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
