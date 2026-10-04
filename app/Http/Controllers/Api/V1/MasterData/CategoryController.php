<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Catalog\ActivateCategory;
use App\Application\Catalog\CatalogQueries;
use App\Application\Catalog\CreateCategory;
use App\Application\Catalog\DeactivateCategory;
use App\Application\Catalog\UpdateCategory;
use App\Application\Shared\Command\Patch;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use App\Http\Resources\Api\V1\MasterDataResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class CategoryController extends ApiController
{
    public function index(Request $request, CatalogQueries $queries): JsonResponse
    {
        return $this->listed($queries->categories($this->status($request)), MasterDataResources::category(...));
    }

    public function show(string $id, ProductCategoryRepository $categories): JsonResponse
    {
        return $this->single(MasterDataResources::category($categories->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateCategory $create): JsonResponse
    {
        $v = $request->validate(['code' => R::code(), 'name' => R::name(), 'parent_id' => ['nullable', 'uuid']]);

        return $this->command($request, 'category.create',
            fn (Actor $a) => $create->execute($a, $v['code'], $v['name'], $v['parent_id'] ?? null),
            MasterDataResources::category(...), 201, 'product_category');
    }

    public function update(Request $request, string $id, UpdateCategory $update): JsonResponse
    {
        $v = $request->validate(['name' => R::name(false), 'parent_id' => ['sometimes', 'nullable', 'uuid'], 'expected_version' => R::expectedVersion()]);

        return $this->command($request, 'category.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), $v['expected_version'] ?? null),
            MasterDataResources::category(...));
    }

    public function activate(Request $request, string $id, ActivateCategory $activate): JsonResponse
    {
        return $this->command($request, 'category.activate', fn (Actor $a) => $activate->execute($a, $id), MasterDataResources::category(...));
    }

    public function deactivate(Request $request, string $id, DeactivateCategory $deactivate): JsonResponse
    {
        return $this->command($request, 'category.deactivate', fn (Actor $a) => $deactivate->execute($a, $id), MasterDataResources::category(...));
    }
}
