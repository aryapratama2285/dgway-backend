<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Application\Operations\OperationsQueries;
use App\Application\Operations\OwnerDashboard;
use App\Application\Shared\Query\PageRequest;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner operational reads (A5-B §17): dashboard, daily activity, search, operational defaults. */
final class OperationsController extends ApiController
{
    private const ACTIVITY_TYPES = ['PURCHASE_ORDER', 'GOODS_RECEIPT', 'LOSS', 'SALE', 'PAYMENT', 'CUSTOMER_RETURN', 'PAYMENT_REVERSAL', 'SALE_REVERSAL', 'GOODS_RECEIPT_REVERSAL', 'INTERNAL_CONSUMPTION', 'INVENTORY_ADJUSTMENT'];

    public function dashboard(Request $request, OwnerDashboard $dashboard, Clock $clock): JsonResponse
    {
        $date = $this->date($request, $clock);

        return $this->single($dashboard->forDate($date, (string) config('dgway.business_timezone'), (string) config('dgway.currency')));
    }

    public function activity(Request $request, OperationsQueries $q, Clock $clock): JsonResponse
    {
        $date = $this->date($request, $clock);
        $v = $request->validate(['type' => ['nullable', Rule::in(self::ACTIVITY_TYPES)]]);
        $page = $q->activity($date, $v['type'] ?? null, $this->pageRequest($request));
        $opening = $q->receivablesAsOf(BusinessDate::fromString($date)->addDays(-1)->toString())['total'];
        $closing = $q->receivablesAsOf($date)['total'];

        return new JsonResponse([
            'data' => $page->items,
            'meta' => ['page' => $page->request->page, 'per_page' => $page->request->perPage, 'total' => $page->total, 'last_page' => $page->lastPage()],
            'summary' => [
                'business_date' => $date,
                'receivable_change' => [
                    'opening' => $opening,
                    'closing' => $closing,
                    'change' => Money::of($closing)->minus(Money::of($opening))->toStorage(),
                ],
            ],
        ]);
    }

    public function search(Request $request, OperationsQueries $q): JsonResponse
    {
        $v = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['nullable', Rule::in(['all', 'products', 'partners', 'documents'])],
        ]);

        return $this->paged($q->search($v['q'], $v['type'] ?? 'all', $this->pageRequest($request)), fn (array $r) => $r);
    }

    /** Operational defaults: Flutter reads these instead of hard-coding ids, currency or page sizes. */
    public function config(OperationsQueries $q, Clock $clock): JsonResponse
    {
        return $this->single([
            'api_version' => 'v1',
            'currency' => (string) config('dgway.currency'),
            'business_timezone' => (string) config('dgway.business_timezone'),
            'business_date' => $clock->today()->toString(),
            'server_time_utc' => $clock->now()->format(DATE_ATOM),
            'default_node' => $q->nodeByCode((string) config('dgway.default_node_code')),
            'walk_in_customer' => $q->partnerByCode((string) config('dgway.walk_in_customer_code')),
            'pagination' => ['default_per_page' => (int) config('dgway.default_page_size'), 'max_per_page' => PageRequest::MAX_PER_PAGE],
            'payment_methods' => array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases()),
            'stock_buckets' => ['AVAILABLE', 'QUARANTINE', 'RETURN_PENDING'],
            'idempotency' => ['header' => 'Idempotency-Key', 'format' => 'UUID', 'retention_days' => (int) config('dgway.idempotency_retention_days')],
        ]);
    }

    private function date(Request $request, Clock $clock): string
    {
        $v = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $v['date'] ?? $clock->today()->toString();
    }
}
