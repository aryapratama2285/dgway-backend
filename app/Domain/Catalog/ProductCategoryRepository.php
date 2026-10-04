<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Identity\Id;

interface ProductCategoryRepository
{
    /** @throws \App\Domain\Shared\Exception\EntityNotFound */
    public function get(Id $id): ProductCategory;

    /** Loads and row-locks the category for the current transaction. */
    public function getForUpdate(Id $id): ProductCategory;

    /** @throws \App\Domain\Shared\Exception\DuplicateCode */
    public function add(ProductCategory $category): void;

    /** @throws \App\Domain\Shared\Exception\ConcurrentModification */
    public function save(ProductCategory $category): void;

    /** @return list<Id> ids from the given category up to its root, starting with the category itself */
    public function ancestorIds(Id $id): array;

    public function countActiveChildren(Id $id): int;

    /** Number of levels in the subtree rooted at this category (1 = no children). */
    public function subtreeHeight(Id $id): int;
}
