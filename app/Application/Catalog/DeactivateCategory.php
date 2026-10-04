<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class DeactivateCategory
{
    public function __construct(
        private readonly ProductCategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $categoryId): ProductCategory
    {
        return $this->tx->run(function () use ($actor, $categoryId): ProductCategory {
            $category = $this->categories->getForUpdate(Id::fromString($categoryId));
            $before = $category->snapshot();

            if ($category->deactivate(
                $this->categories->countActiveChildren($category->id()),
                $this->products->countActiveInCategory($category->id()),
            )) {
                $this->categories->save($category);
                $this->audit->changed($actor, 'category.deactivated', 'product_category', $category, $before);
            }

            return $category;
        });
    }
}
