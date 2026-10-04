<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Shared\Audit\AuditScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for every API request (A5-B §21).
 *
 * - accepts the client's X-Request-Id when it is a safe token
 *   ([A-Za-z0-9._-], 8–64 chars), otherwise generates a UUIDv7;
 * - echoes it in the response header;
 * - shares it with every log line of the request (Log::shareContext);
 * - opens an AuditScope so every audit row written by the request carries
 *   context.request_id next to idempotency_key and command.
 *
 * request id ↔ log lines ↔ audit rows ↔ idempotency key ↔ document id
 * can therefore be joined in both directions.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    public function __construct(private readonly AuditScope $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) === 1 ? $given : (string) Str::uuid7();
        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext(['request_id' => $id]);

        $response = $this->audit->within(['request_id' => $id], fn () => $next($request));
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
