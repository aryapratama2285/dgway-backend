<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Application\Sales\SalesQueries;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Who still owes DG Way, and how much?" — always computed from sales/payments, never edited. */
final class ReceivableController extends ApiController
{
    public function bySale(Request $request, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $f = $request->validate([
            'customer_id' => ['nullable', 'uuid'], 'overdue' => ['nullable', 'boolean'], 'due_on_or_before' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($queries->receivablesBySale($f, $clock->today()->toString(), $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function byCustomer(Request $request, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);

        return $this->paged($queries->receivablesByCustomer($f, $clock->today()->toString(), $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function summary(SalesQueries $queries, Clock $clock): JsonResponse
    {
        return $this->single($queries->receivableSummary($clock->today()->toString()));
    }
}
