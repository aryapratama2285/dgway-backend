<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\CancelPurchaseOrder;
use App\Application\Procurement\ConfirmPurchaseOrder;
use App\Application\Procurement\CreatePurchaseOrder;
use App\Application\Procurement\ProcurementQueries;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\ProcurementResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PurchaseOrderController extends ApiController
{
    public function index(Request $request, ProcurementQueries $queries): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'CONFIRMED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'])],
            'supplier_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($queries->purchaseOrders($f, $this->pageRequest($request)), ProcurementResources::purchaseOrder(...));
    }

    public function show(string $id, PurchaseOrderRepository $orders): JsonResponse
    {
        return $this->single(ProcurementResources::purchaseOrder($orders->get(Id::fromString($id))));
    }

    public function store(Request $request, CreatePurchaseOrder $create): JsonResponse
    {
        $v = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        return $this->command($request, 'purchase_order.create',
            fn (Actor $a) => $create->execute($a, $v['supplier_id'], $v['node_id'], $v['lines'], $v['business_date'] ?? null, $v['notes'] ?? null),
            ProcurementResources::purchaseOrder(...), 201, 'purchase_order');
    }

    public function confirm(Request $request, string $id, ConfirmPurchaseOrder $confirm): JsonResponse
    {
        $v = $request->validate(['expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'purchase_order.confirm', fn (Actor $a) => $confirm->execute($a, $id, $v['expected_version'] ?? null), ProcurementResources::purchaseOrder(...));
    }

    public function cancel(Request $request, string $id, CancelPurchaseOrder $cancel): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'purchase_order.cancel', fn (Actor $a) => $cancel->execute($a, $id, $v['reason'], $v['expected_version'] ?? null), ProcurementResources::purchaseOrder(...));
    }
}
