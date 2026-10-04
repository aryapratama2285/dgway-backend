<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Application\Sales\CancelSale;
use App\Application\Sales\ConfirmSale;
use App\Application\Sales\ConfirmSaleWithCreditOverride;
use App\Application\Sales\CreateSale;
use App\Application\Sales\RecordCustomerReturn;
use App\Application\Sales\RecordPayment;
use App\Application\Sales\ReverseSale;
use App\Application\Sales\SaleCommandResult;
use App\Application\Sales\SalesQueries;
use App\Domain\Sales\CustomerReturnRepository;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\SalesResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SaleController extends ApiController
{
    private const METHODS = ['CASH', 'BANK_TRANSFER', 'QRIS'];

    public function index(Request $request, SalesQueries $queries): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'CONFIRMED', 'COMPLETED', 'CANCELLED', 'REVERSED'])],
            'payment_status' => ['nullable', Rule::in(['UNPAID', 'PARTIALLY_PAID', 'PAID', 'NONE'])],
            'customer_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'], 'walk_in' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->paged($queries->sales($f, $this->pageRequest($request)), fn (array $row) => $row);
    }

    public function show(string $id, SaleRepository $sales, PaymentRepository $payments, CustomerReturnRepository $returns, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $sale = $sales->get(Id::fromString($id));

        return $this->single(SalesResources::sale($sale, $queries->customerLabel($sale->customerId?->value), $clock->today(),
            $payments->paymentsOfSale($sale->id()), $returns->returnsOfSale($sale->id())));
    }

    public function store(Request $request, CreateSale $create, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([
            'customer_id' => ['nullable', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'confirm' => ['nullable', 'boolean'],
            ...self::paymentRules('payments.*.'),
            'payments' => ['nullable', 'array', 'max:5'],
        ]);
        if (! empty($v['payments']) && empty($v['confirm'])) {
            throw ValidationException::withMessages(['payments' => 'Payments can only be given together with confirm=true.']);
        }

        return $this->command($request, 'sale.create',
            fn (Actor $a) => $create->execute($a, $v['customer_id'] ?? null, $v['node_id'], $v['lines'], $v['business_date'] ?? null, $v['notes'] ?? null,
                (bool) ($v['confirm'] ?? false), $v['payments'] ?? []),
            $this->present($queries, $clock), 201, 'sale');
    }

    public function confirm(Request $request, string $id, ConfirmSale $confirm, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'payments' => ['nullable', 'array', 'max:5'],
            ...self::paymentRules('payments.*.'),
        ]);

        return $this->command($request, 'sale.confirm', fn (Actor $a) => $confirm->execute($a, $id, $v['payments'] ?? [], $v['expected_version'] ?? null),
            $this->present($queries, $clock));
    }

    /** A5-B §18: OWNER-only, reasoned credit-limit override (the limit itself is not changed). */
    public function confirmWithCreditOverride(Request $request, string $id, ConfirmSaleWithCreditOverride $confirm, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'payments' => ['nullable', 'array', 'max:5'],
            ...self::paymentRules('payments.*.'),
        ]);

        return $this->command($request, 'sale.confirm_with_credit_override',
            fn (Actor $a) => $confirm->execute($a, $id, $v['reason'], $v['payments'] ?? [], $v['expected_version'] ?? null),
            $this->present($queries, $clock));
    }

    public function cancel(Request $request, string $id, CancelSale $cancel, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'sale.cancel', fn (Actor $a) => $cancel->execute($a, $id, $v['reason'], $v['expected_version'] ?? null),
            $this->present($queries, $clock));
    }

    public function reverse(Request $request, string $id, ReverseSale $reverse, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'reverse_payments' => ['nullable', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'sale.reverse',
            fn (Actor $a) => $reverse->execute($a, $id, $v['reason'], (bool) ($v['reverse_payments'] ?? false), $v['expected_version'] ?? null),
            $this->present($queries, $clock));
    }

    public function pay(Request $request, string $id, RecordPayment $record, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([...self::paymentRules(''), 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'payment.record', fn (Actor $a) => $record->execute($a, $id, $v, $v['expected_version'] ?? null),
            fn (SaleCommandResult $r) => SalesResources::payment($r->document) + ['sale' => $this->present($queries, $clock)($r->sale)], 201, 'payment');
    }

    public function recordReturn(Request $request, string $id, RecordCustomerReturn $record, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'refund_method' => ['nullable', Rule::in(self::METHODS)],
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sale_line_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.disposition' => ['required', Rule::in(['RETURN_TO_AVAILABLE', 'RETURN_TO_QUARANTINE', 'LOSS'])],
            'lines.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->command($request, 'customer_return.record',
            fn (Actor $a) => $record->execute($a, $id, $v['lines'], $v['reason'], $v['refund_method'] ?? null, $v['expected_version'] ?? null),
            fn (SaleCommandResult $r) => SalesResources::customerReturn($r->document) + ['sale' => $this->present($queries, $clock)($r->sale)], 201, 'customer_return');
    }

    /** @return array<string, list<mixed>> */
    public static function paymentRules(string $prefix): array
    {
        return [
            "{$prefix}method" => [$prefix === '' ? 'required' : 'required_with:payments', Rule::in(self::METHODS)],
            "{$prefix}amount" => [$prefix === '' ? 'required' : 'required_with:payments', 'numeric', 'gt:0'],
            "{$prefix}reference" => ['nullable', 'string', 'max:100'],
            "{$prefix}notes" => ['nullable', 'string', 'max:500'],
            "{$prefix}paid_at" => ['nullable', 'date'],
            "{$prefix}business_date" => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    private function present(SalesQueries $queries, Clock $clock): \Closure
    {
        return fn (Sale $s) => SalesResources::sale($s, $queries->customerLabel($s->customerId?->value), $clock->today());
    }
}
