<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Application\Sales\SalesQueries;
use App\Domain\Sales\CustomerReturnRepository;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\SalesResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerReturnController extends ApiController
{
    public function index(Request $request, SalesQueries $queries): JsonResponse
    {
        $f = $request->validate([
            'sale_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->paged($queries->customerReturns($f, $this->pageRequest($request)), fn (array $row) => $row);
    }

    public function show(string $id, CustomerReturnRepository $returns): JsonResponse
    {
        return $this->single(SalesResources::customerReturn($returns->get(Id::fromString($id))));
    }
}
