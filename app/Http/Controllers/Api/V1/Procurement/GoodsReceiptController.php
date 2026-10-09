<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Procurement;

use App\Application\Procurement\CancelGoodsReceipt;
use App\Application\Procurement\CreateGoodsReceipt;
use App\Application\Procurement\PostGoodsReceipt;
use App\Application\Procurement\ProcurementQueries;
use App\Application\Procurement\ReverseGoodsReceipt;
use App\Application\Procurement\UpdateGoodsReceipt;
use App\Application\Shared\Command\Patch;
use App\Domain\Procurement\GoodsReceipt;
use Illuminate\Support\Arr;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\ProcurementResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GoodsReceiptController extends ApiController
{
    public function index(Request $request, ProcurementQueries $queries): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'REVERSED', 'CANCELLED'])],
            'supplier_id' => ['nullable', 'uuid'], 'node_id' => ['nullable', 'uuid'], 'purchase_order_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $page = $queries->goodsReceipts($f, $this->pageRequest($request));
        $refs = $queries->references($page->items);

        return $this->paged($page, fn (GoodsReceipt $gr) => ProcurementResources::goodsReceipt($gr, $refs));
    }

    public function show(string $id, GoodsReceiptRepository $receipts, ProcurementQueries $queries): JsonResponse
    {
        return $this->single(self::presenter($queries)($receipts->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateGoodsReceipt $create, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...self::lineRules('required'),
        ]);

        return $this->command($request, 'goods_receipt.create',
            fn (Actor $a) => $create->execute($a, $v['supplier_id'], $v['node_id'], $v['lines'], $v['purchase_order_id'] ?? null,
                $v['business_date'] ?? null, $v['received_at'] ?? null, $v['notes'] ?? null),
            self::presenter($queries), 201, 'goods_receipt');
    }

    /** F4A: edit a DRAFT receipt (date, received_at, notes, lines). Supplier/node/PO are fixed. */
    public function update(Request $request, string $id, UpdateGoodsReceipt $update, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate([
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'received_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...self::lineRules('sometimes'),
            'supplier_id' => ['prohibited'], 'node_id' => ['prohibited'], 'purchase_order_id' => ['prohibited'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'goods_receipt.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), (int) $v['expected_version']),
            self::presenter($queries));
    }

    /** @return array<string, list<string>> */
    private static function lineRules(string $presence): array
    {
        return [
            'lines' => [$presence, 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.purchase_order_line_id' => ['nullable', 'uuid'],
            'lines.*.manufactured_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.best_before_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.collection_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.supplier_reference' => ['nullable', 'string', 'max:60'],
            'lines.*.dispositions' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.dispositions.*.type' => ['required', 'string', 'max:24'],
            'lines.*.dispositions.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.dispositions.*.target_product_id' => ['nullable', 'uuid'],
            'lines.*.dispositions.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    private static function presenter(ProcurementQueries $queries): \Closure
    {
        return fn (GoodsReceipt $gr): array => ProcurementResources::goodsReceipt($gr, $queries->references([$gr]));
    }

    public function post(Request $request, string $id, PostGoodsReceipt $post, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate(['expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'goods_receipt.post', fn (Actor $a) => $post->execute($a, $id, $v['expected_version'] ?? null), self::presenter($queries));
    }

    public function reverse(Request $request, string $id, ReverseGoodsReceipt $reverse, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'goods_receipt.reverse', fn (Actor $a) => $reverse->execute($a, $id, $v['reason'], $v['expected_version'] ?? null), self::presenter($queries));
    }

    public function cancel(Request $request, string $id, CancelGoodsReceipt $cancel, ProcurementQueries $queries): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'max:500'], 'expected_version' => ['nullable', 'integer', 'min:1']]);

        return $this->command($request, 'goods_receipt.cancel', fn (Actor $a) => $cancel->execute($a, $id, $v['reason'], $v['expected_version'] ?? null), self::presenter($queries));
    }
}
