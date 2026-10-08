<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Application\Inventory\InventoryQueries;
use App\Application\Inventory\SetReorderPoint;
use App\Domain\Inventory\ReorderPoint;
use App\Domain\Shared\Identity\Actor;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F3: low-stock thresholds per product × node. Configuration, not stock:
 * nothing here changes a balance. min_quantity is in the product's base UOM;
 * null disables monitoring.
 */
final class ReorderPointController extends ApiController
{
    public function index(Request $request, InventoryQueries $q): JsonResponse
    {
        $v = $request->validate(['product_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid']]);

        return $this->paged($q->reorderPoints($v, $this->pageRequest($request)), fn (array $r) => $r);
    }

    /** Upsert by product × node. */
    public function store(Request $request, SetReorderPoint $set): JsonResponse
    {
        $v = $request->validate([
            'product_id' => ['required', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'min_quantity' => ['present', 'nullable', 'numeric', 'gte:0'],
            'expected_version' => R::expectedVersion(),
        ]);

        return $this->command($request, 'inventory.reorder_point_set',
            fn (Actor $a) => $set->execute($a, $v['product_id'], $v['node_id'], $v['min_quantity'] === null ? null : (string) $v['min_quantity'], $v['expected_version'] ?? null),
            fn (ReorderPoint $p) => self::present($p), 200, 'reorder_point');
    }

    /** @return array<string, mixed> */
    private static function present(ReorderPoint $p): array
    {
        return [
            'id' => $p->id()->value,
            'product_id' => $p->productId()->value,
            'node_id' => $p->nodeId()->value,
            'min_quantity' => $p->minQuantity()?->toStorage(),
            'uom' => $p->uom()->code,
            'version' => $p->version(),
        ];
    }
}
