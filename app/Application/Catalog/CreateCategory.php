<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class CreateCategory
{
    public function __construct(
        private readonly ProductCategoryRepository $categories,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $code, string $name, ?string $parentId = null): ProductCategory
    {
        return $this->tx->run(function () use ($actor, $code, $name, $parentId): ProductCategory {
            $parent = $parentId === null ? null : $this->categories->get(Id::fromString($parentId));
            $ancestors = $parent === null ? [] : $this->categories->ancestorIds($parent->id());

            $category = ProductCategory::create($this->ids->next(), BusinessCode::of($code, 'Category code'), Name::of($name), $parent, $ancestors);
            $this->categories->add($category);
            $this->audit->created($actor, 'category.created', 'product_category', $category);

            return $category;
        });
    }
}
