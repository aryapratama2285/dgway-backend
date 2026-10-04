<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Application\Inventory\InventoryQueries;
use App\Application\Inventory\RecordInventoryOperation;
use App\Application\Inventory\StockProjection;
use App\Domain\Shared\Identity\Actor;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\ProcurementResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventory reads + explicit stock operations. There is deliberately no
 * endpoint that sets a stock quantity: every change is an operation with a
 * reason that produces ledger movements.
 */
final class InventoryController extends ApiController
{
    private const BUCKETS = ['AVAILABLE', 'QUARANTINE', 'RETURN_PENDING'];

    public function current(Request $request, InventoryQueries $q): JsonResponse
    {
        $v = $request->validate(['product_id' => ['required', 'uuid'], 'node_id' => ['required', 'uuid']]);

        return $this->single($q->currentStock($v['product_id'], $v['node_id']));
    }

    public function balances(Request $request, InventoryQueries $q): JsonResponse
    {
        $v = $request->validate([
            'node_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'],
            'bucket' => ['nullable', Rule::in(self::BUCKETS)], 'include_zero' => ['nullable', 'boolean'],
        ]);

        return $this->paged($q->balances($v['node_id'] ?? null, $v['product_id'] ?? null, $v['bucket'] ?? null, (bool) ($v['include_zero'] ?? false), $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function movements(Request $request, InventoryQueries $q): JsonResponse
    {
        $f = $request->validate([
            'product_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'], 'bucket' => ['nullable', Rule::in(self::BUCKETS)],
            'movement_type' => ['nullable', 'string', 'max:24'], 'source_document_id' => ['nullable', 'uuid'], 'correlation_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = $q->movements($f, $this->pageRequest($request));

        return new JsonResponse(['data' => $page->items, 'meta' => [
            'page' => $page->request->page, 'per_page' => $page->request->perPage, 'total' => $page->total, 'last_page' => $page->lastPage(),
        ]]);
    }

    public function integrity(StockProjection $projection): JsonResponse
    {
        $mismatches = $projection->verify();

        return new JsonResponse(['data' => ['consistent' => $mismatches === [], 'mismatches' => $mismatches]]);
    }

    public function loss(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $this->validateOp($request);

        return $this->command($request, 'inventory.loss',
            fn (Actor $a) => $ops->recordLoss($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['reason'], $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    public function regrade(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $this->validateOp($request, ['to_product_id' => ['required', 'uuid']]);

        return $this->command($request, 'inventory.regrade',
            fn (Actor $a) => $ops->regrade($a, $v['node_id'], $v['product_id'], $v['to_product_id'], (string) $v['quantity'], $v['reason'], $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    public function recovery(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $this->validateOp($request, ['from_bucket' => ['required', Rule::in(['RETURN_PENDING', 'QUARANTINE'])]]);

        return $this->command($request, 'inventory.recovery',
            fn (Actor $a) => $ops->recover($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['from_bucket'], $v['reason'], $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    public function supplierReturn(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $this->validateOp($request);

        return $this->command($request, 'inventory.supplier_return',
            fn (Actor $a) => $ops->shipSupplierReturn($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['reason'], $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    public function adjustment(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $this->validateOp($request, ['direction' => ['required', Rule::in(['IN', 'OUT'])]]);

        return $this->command($request, 'inventory.adjustment',
            fn (Actor $a) => $ops->adjust($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['direction'], $v['reason'], $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function validateOp(Request $request, array $extra = []): array
    {
        return $request->validate([
            'node_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'uom' => ['nullable', 'string', 'max:16'],
            'reason' => ['required', 'string', 'max:500'],
            ...$extra,
        ]);
    }
}
