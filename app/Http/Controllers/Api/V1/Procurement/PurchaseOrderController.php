<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\CancelPurchaseOrder;
use App\Application\Procurement\ClosePurchaseOrder;
use App\Application\Procurement\ConfirmPurchaseOrder;
use App\Application\Procurement\CreatePurchaseOrder;
use App\Application\Procurement\ProcurementQueries;
use App\Application\Procurement\UpdatePurchaseOrder;
use App\Application\Shared\Command\Patch;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\ProcurementResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class PurchaseOrderController extends ApiController
{
    private const STATUSES = ['DRAFT', 'CONFIRMED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED', 'CANCELLED'];

    private const CLOSE_REASONS = ['SUPPLIER_SHORTFALL', 'PURCHASE_STOPPED', 'OTHER'];

    public function index(Request $request, ProcurementQueries $queries): JsonResponse
    {
        $f = $request->validate([
            // F4A: `open` = CONFIRMED + PARTIALLY_RECEIVED (waiting for goods)
            'status' => ['nullable', Rule::in([...self::STATUSES, 'OPEN'])],
            'supplier_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = $queries->purchaseOrders($f, $this->pageRequest($request));
        $refs = $queries->references($page->items);

        return $this->paged($page, fn (PurchaseOrder $po) => ProcurementResources::purchaseOrder($po, $refs));
    }

    public function show(string $id, PurchaseOrderRepository $orders, ProcurementQueries $queries): JsonResponse
    {
        return $this->single(self::presenter($queries)($orders->get(Id::fromString($id))));
    }

    public function store(Request $request, CreatePurchaseOrder $create, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...self::lineRules('required'),
        ]);

        return $this->command($request, 'purchase_order.create',
            fn (Actor $a) => $create->execute($a, $v['supplier_id'], $v['node_id'], $v['lines'], $v['business_date'] ?? null, $v['notes'] ?? null),
            self::presenter($queries), 201, 'purchase_order');
    }

    /** F4A: edit a DRAFT (absent fields unchanged; `lines` replaces all lines). */
    public function update(Request $request, string $id, UpdatePurchaseOrder $update, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate([
            'supplier_id' => ['sometimes', 'uuid'],
            'node_id' => ['sometimes', 'uuid'],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...self::lineRules('sometimes'),
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'purchase_order.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), (int) $v['expected_version']),
            self::presenter($queries));
    }

    public function confirm(Request $request, string $id, ConfirmPurchaseOrder $confirm, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate(['expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'purchase_order.confirm', fn (Actor $a) => $confirm->execute($a, $id, $v['expected_version'] ?? null), self::presenter($queries));
    }

    public function cancel(Request $request, string $id, CancelPurchaseOrder $cancel, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'purchase_order.cancel', fn (Actor $a) => $cancel->execute($a, $id, $v['reason'], $v['expected_version'] ?? null), self::presenter($queries));
    }

    /** F4A: finish a PARTIALLY_RECEIVED / RECEIVED order (CLOSED, terminal). */
    public function close(Request $request, string $id, ClosePurchaseOrder $close, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate([
            'reason_code' => ['required', Rule::in(self::CLOSE_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'purchase_order.close',
            fn (Actor $a) => $close->execute($a, $id, $v['reason_code'], $v['note'] ?? null, $v['expected_version'] ?? null), self::presenter($queries));
    }

    /** Presents one order with its display names (supplier, node, products). */
    private static function presenter(ProcurementQueries $queries): \Closure
    {
        return fn (PurchaseOrder $po): array => ProcurementResources::purchaseOrder($po, $queries->references([$po]));
    }

    /** @return array<string, list<string>> */
    private static function lineRules(string $presence): array
    {
        return [
            'lines' => [$presence, 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // F4A: purchasing unit (default: the product's base unit) and the agreed price per that unit
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
