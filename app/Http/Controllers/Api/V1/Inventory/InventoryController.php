<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Application\Inventory\InventoryQueries;
use App\Application\Inventory\RecordInventoryOperation;
use App\Application\Inventory\StockProjection;
use App\Domain\Inventory\InventoryOperationType;
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

    private const CONSUMPTION_REASONS = ['PACKING', 'LABELING', 'DELIVERY', 'ADMIN', 'SAMPLE', 'OTHER'];

    private const ADJUSTMENT_REASONS = ['COUNT_CORRECTION', 'FOUND_STOCK', 'SHRINKAGE', 'OTHER'];

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

    /**
     * Two mutually exclusive modes:
     * - delta (A3, unchanged): {quantity, direction IN|OUT};
     * - counted (F3): {counted_quantity, expected_available?} — the server computes
     *   the delta after locking the position.
     * Both need the `inventory.adjust` permission; reason_code is optional.
     */
    public function adjustment(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        if ($request->has('counted_quantity')) {
            $v = $request->validate([
                'node_id' => ['required', 'uuid'],
                'product_id' => ['required', 'uuid'],
                'counted_quantity' => ['required', 'numeric', 'gte:0'],
                'expected_available' => ['nullable', 'numeric', 'gte:0'],
                'uom' => ['nullable', 'string', 'max:16'],
                'reason' => ['required', 'string', 'max:500'],
                'reason_code' => ['nullable', Rule::in(self::ADJUSTMENT_REASONS)],
                'quantity' => ['prohibited'],
                'direction' => ['prohibited'],
            ]);

            return $this->command($request, 'inventory.adjustment',
                fn (Actor $a) => $ops->adjustToCount($a, $v['node_id'], $v['product_id'], (string) $v['counted_quantity'],
                    isset($v['expected_available']) ? (string) $v['expected_available'] : null, $v['reason'], $v['uom'] ?? null, $v['reason_code'] ?? null),
                ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
        }
        $v = $this->validateOp($request, [
            'direction' => ['required', Rule::in(['IN', 'OUT'])],
            'reason_code' => ['nullable', Rule::in(self::ADJUSTMENT_REASONS)],
        ]);

        return $this->command($request, 'inventory.adjustment',
            fn (Actor $a) => $ops->adjust($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['direction'], $v['reason'], $v['uom'] ?? null, $v['reason_code'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    /** F3: DG Way consumed its own material (packaging, labels, tape …). */
    public function consumption(Request $request, RecordInventoryOperation $ops): JsonResponse
    {
        $v = $request->validate([
            'node_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'uom' => ['nullable', 'string', 'max:16'],
            'reason_code' => ['required', Rule::in(self::CONSUMPTION_REASONS)],
            'notes' => ['nullable', 'string', 'max:500'],
            'reference_text' => ['nullable', 'string', 'max:60'],
        ]);

        return $this->command($request, 'inventory.internal_consumption',
            fn (Actor $a) => $ops->consume($a, $v['node_id'], $v['product_id'], (string) $v['quantity'], $v['reason_code'], $v['notes'] ?? null, $v['reference_text'] ?? null, $v['uom'] ?? null),
            ProcurementResources::inventoryOperation(...), 201, 'inventory_operation');
    }

    /** F3: inventory list per product × node (read model over the balance projection). */
    public function stock(Request $request, InventoryQueries $q): JsonResponse
    {
        $v = $request->validate([
            'node_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'classification' => ['nullable', Rule::in(['SELLABLE', 'PACKAGING', 'CONSUMABLE'])],
            'low_stock' => ['nullable', 'boolean'],
            'include_zero' => ['nullable', 'boolean'],
        ]);

        return $this->paged($q->stock([
            'node_id' => $v['node_id'] ?? null,
            'product_id' => $v['product_id'] ?? null,
            'q' => $v['q'] ?? null,
            'classification' => $v['classification'] ?? null,
            'low_stock' => (bool) ($v['low_stock'] ?? false),
            'include_zero' => (bool) ($v['include_zero'] ?? false),
        ], $this->pageRequest($request)), fn (array $r) => $r);
    }

    /** F3: inventory operation history (newest first). */
    public function operations(Request $request, InventoryQueries $q): JsonResponse
    {
        $v = $request->validate([
            'type' => ['nullable', Rule::in(array_map(fn (InventoryOperationType $t) => $t->value, InventoryOperationType::cases()))],
            'product_id' => ['nullable', 'uuid'],
            'node_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:32'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($q->operations($v, $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function operation(string $id, InventoryQueries $q): JsonResponse
    {
        return $this->single($q->operation($id));
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
