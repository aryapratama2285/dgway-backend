<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class ActivateCategory
{
    public function __construct(
        private readonly ProductCategoryRepository $categories,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $categoryId): ProductCategory
    {
        return $this->tx->run(function () use ($actor, $categoryId): ProductCategory {
            $category = $this->categories->getForUpdate(Id::fromString($categoryId));
            $parentId = $category->parentId();
            if ($parentId !== null && ! $this->categories->get($parentId)->isActive()) {
                throw InvariantViolation::because('CATEGORY_PARENT_INACTIVE', 'Activate the parent category first.');
            }

            $before = $category->snapshot();
            if ($category->activate()) {
                $this->categories->save($category);
                $this->audit->changed($actor, 'category.activated', 'product_category', $category, $before);
            }

            return $category;
        });
    }
}
