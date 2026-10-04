<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Application\Inventory\LotQueries;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lot traceability reads (A5-B). Read-only: lots are created by posting a
 * goods receipt, a regrade or a stock-count adjustment, never through here.
 * {lot} accepts the lot UUID or its number (e.g. LOT-261004-001).
 */
final class LotController extends ApiController
{
    public function index(Request $request, LotQueries $q): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'], 'supplier_id' => ['nullable', 'uuid'], 'goods_receipt_id' => ['nullable', 'uuid'],
            'origin' => ['nullable', Rule::in(['RECEIPT', 'REGRADE', 'ADJUSTMENT', 'LEGACY'])],
            'status' => ['nullable', Rule::in(['active', 'depleted', 'all'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($q->lots($f, $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function show(string $lot, LotQueries $q): JsonResponse
    {
        return $this->single($q->lot($lot));
    }

    public function movements(Request $request, string $lot, LotQueries $q): JsonResponse
    {
        return $this->paged($q->movements($lot, $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function lineage(string $lot, LotQueries $q): JsonResponse
    {
        return $this->single($q->lineage($lot));
    }

    public function destinations(Request $request, string $lot, LotQueries $q): JsonResponse
    {
        return $this->paged($q->destinations($lot, $this->pageRequest($request)), fn (array $r) => $r);
    }

    public function balances(Request $request, LotQueries $q): JsonResponse
    {
        $v = $request->validate([
            'node_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'],
            'bucket' => ['nullable', Rule::in(['AVAILABLE', 'QUARANTINE', 'RETURN_PENDING'])], 'include_zero' => ['nullable', 'boolean'],
        ]);

        return $this->paged($q->balances($v['node_id'] ?? null, $v['product_id'] ?? null, $v['bucket'] ?? null, (bool) ($v['include_zero'] ?? false), $this->pageRequest($request)), fn (array $r) => $r);
    }
}
