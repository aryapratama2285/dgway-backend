<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Catalog\SetProductUomConversion;
use App\Application\Procurement\ProcurementQueries;
use App\Domain\Catalog\ProductUomConversion;
use App\Domain\Shared\Identity\Actor;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * F4A: purchasing-unit conversions of a product ("1 TRAY = 15 KG").
 * Changing one never changes existing documents (they keep a snapshot).
 */
final class ProductUomConversionController extends ApiController
{
    public function index(string $id, ProcurementQueries $queries): JsonResponse
    {
        return new JsonResponse(['data' => $queries->uomConversions($id)]);
    }

    /** Upsert by product × unit; `is_active: false` retires the unit for new documents. */
    public function put(Request $request, string $id, string $uom, SetProductUomConversion $set): JsonResponse
    {
        $v = $request->validate([
            'factor_to_base' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
            'expected_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->command($request, 'product.uom_conversion_set',
            fn (Actor $a) => $set->execute($a, $id, $uom, (string) $v['factor_to_base'], (bool) ($v['is_active'] ?? true), $v['expected_version'] ?? null),
            fn (ProductUomConversion $c) => [
                'id' => $c->id()->value,
                'product_id' => $c->productId()->value,
                'uom' => $c->conversion()->unit->code,
                'base_uom' => $c->conversion()->baseUnit->code,
                'factor_to_base' => $c->conversion()->factorToStorage(),
                'is_active' => $c->isActive(),
                'version' => $c->version(),
            ], 200, 'product_uom_conversion');
    }
}
