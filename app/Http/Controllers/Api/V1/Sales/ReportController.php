<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Application\Sales\SalesQueries;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner dashboard read models (no analytics warehouse). */
final class ReportController extends ApiController
{
    /** Sales, payments, refunds and receivables for one business day (default today). */
    public function daily(Request $request, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $v['date'] ?? $clock->today()->toString();

        return $this->single($queries->salesSummary($date, $date, $clock->today()->toString()));
    }

    public function sales(Request $request, SalesQueries $queries, Clock $clock): JsonResponse
    {
        [$from, $to] = $this->period($request, $clock);

        return $this->single($queries->salesSummary($from, $to, $clock->today()->toString()));
    }

    public function salesByProduct(Request $request, SalesQueries $queries, Clock $clock): JsonResponse
    {
        [$from, $to] = $this->period($request, $clock);

        return new JsonResponse(['data' => $queries->salesByProduct($from, $to), 'meta' => ['from' => $from, 'to' => $to]]);
    }

    /** @return array{string, string} */
    private function period(Request $request, Clock $clock): array
    {
        $v = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $today = $clock->today()->toString();

        return [$v['date_from'] ?? $today, $v['date_to'] ?? $v['date_from'] ?? $today];
    }
}
