<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Application\Shared\Query\StatusFilter;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductCategory;

/** Read-side port for catalog lists (filters + pagination). */
interface CatalogQueries
{
    /** @return list<ProductCategory> all categories (small set), ordered by code */
    public function categories(StatusFilter $status): array;

    /** @return Page<Product> */
    public function products(StatusFilter $status, ?string $search, ?string $categoryId, PageRequest $page): Page;

    /** @return list<array{code: string, name: string, precision: int, is_active: bool}> */
    public function uoms(): array;
}
