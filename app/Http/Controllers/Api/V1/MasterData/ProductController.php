<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Catalog\ActivateProduct;
use App\Application\Catalog\CatalogQueries;
use App\Application\Catalog\CreateProduct;
use App\Application\Catalog\DeactivateProduct;
use App\Application\Catalog\UpdateProduct;
use App\Application\Shared\Command\Patch;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use App\Http\Resources\Api\V1\MasterDataResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class ProductController extends ApiController
{
    public function index(Request $request, CatalogQueries $queries): JsonResponse
    {
        $request->validate(['category_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);

        return $this->paged(
            $queries->products($this->status($request), $request->query('q'), $request->query('category_id'), $this->pageRequest($request)),
            MasterDataResources::product(...),
        );
    }

    public function show(string $id, ProductRepository $products): JsonResponse
    {
        return $this->single(MasterDataResources::product($products->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateProduct $create): JsonResponse
    {
        $v = $request->validate([
            'sku' => R::code(),
            'name' => R::name(),
            'category_id' => ['required', 'uuid'],
            'base_uom' => ['required', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command($request, 'product.create',
            fn (Actor $a) => $create->execute($a, $v['sku'], $v['name'], $v['category_id'], $v['base_uom'], $v['description'] ?? null),
            MasterDataResources::product(...), 201, 'product');
    }

    public function update(Request $request, string $id, UpdateProduct $update): JsonResponse
    {
        $v = $request->validate([
            'name' => R::name(false),
            'category_id' => ['sometimes', 'uuid'],
            'base_uom' => ['sometimes', 'string', 'max:16'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sku' => ['prohibited'],
            'expected_version' => R::expectedVersion(),
        ], ['sku.prohibited' => 'SKU tidak dapat diubah. Buat produk baru bila SKU berbeda.']);

        return $this->command($request, 'product.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), $v['expected_version'] ?? null),
            MasterDataResources::product(...));
    }

    public function activate(Request $request, string $id, ActivateProduct $activate): JsonResponse
    {
        return $this->command($request, 'product.activate', fn (Actor $a) => $activate->execute($a, $id), MasterDataResources::product(...));
    }

    public function deactivate(Request $request, string $id, DeactivateProduct $deactivate): JsonResponse
    {
        return $this->command($request, 'product.deactivate', fn (Actor $a) => $deactivate->execute($a, $id), MasterDataResources::product(...));
    }

    public function uoms(CatalogQueries $queries): JsonResponse
    {
        return new JsonResponse(['data' => $queries->uoms()]);
    }
}
