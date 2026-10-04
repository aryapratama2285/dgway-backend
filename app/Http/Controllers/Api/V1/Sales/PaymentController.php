<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Application\Sales\ReversePayment;
use App\Application\Sales\SaleCommandResult;
use App\Application\Sales\SalesQueries;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\SalesResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentController extends ApiController
{
    public function index(Request $request, SalesQueries $queries): JsonResponse
    {
        $f = $request->validate([
            'method' => ['nullable', Rule::in(['CASH', 'BANK_TRANSFER', 'QRIS'])],
            'status' => ['nullable', Rule::in(['POSTED', 'REVERSED'])],
            'sale_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($queries->payments($f, $this->pageRequest($request)), fn (array $row) => $row);
    }

    public function show(string $id, PaymentRepository $payments): JsonResponse
    {
        return $this->single(SalesResources::payment($payments->get(Id::fromString($id))));
    }

    public function reverse(Request $request, string $id, ReversePayment $reverse, SalesQueries $queries, Clock $clock): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->command($request, 'payment.reverse', fn (Actor $a) => $reverse->execute($a, $id, $v['reason']),
            fn (SaleCommandResult $r) => SalesResources::payment($r->document) + ['sale' => SalesResources::sale($r->sale, $queries->customerLabel($r->sale->customerId?->value), $clock->today())]);
    }
}
