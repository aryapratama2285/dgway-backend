<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Identity\Id;

interface ProductRepository
{
    /** @throws \App\Domain\Shared\Exception\EntityNotFound */
    public function get(Id $id): Product;

    public function getForUpdate(Id $id): Product;

    /** @throws \App\Domain\Shared\Exception\DuplicateCode when the SKU exists */
    public function add(Product $product): void;

    /** @throws \App\Domain\Shared\Exception\ConcurrentModification */
    public function save(Product $product): void;

    public function countActiveInCategory(Id $categoryId): int;
}
