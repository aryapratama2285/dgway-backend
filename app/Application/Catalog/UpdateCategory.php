<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

/** Rename a category and/or move it in the hierarchy (code is immutable). */
final class UpdateCategory
{
    public function __construct(
        private readonly ProductCategoryRepository $categories,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $categoryId, Patch $patch, ?int $expectedVersion = null): ProductCategory
    {
        return $this->tx->run(function () use ($actor, $categoryId, $patch, $expectedVersion): ProductCategory {
            $category = $this->categories->getForUpdate(Id::fromString($categoryId));
            ExpectedVersion::check('Product category', $category, $expectedVersion);
            $before = $category->snapshot();
            $changed = false;

            if ($patch->has('name')) {
                $changed = $category->rename(Name::of((string) $patch->string('name'))) || $changed;
            }
            if ($patch->has('parent_id')) {
                $parentId = $patch->string('parent_id');
                $parent = $parentId === null ? null : $this->categories->get(Id::fromString($parentId));
                $ancestors = $parent === null ? [] : $this->categories->ancestorIds($parent->id());
                $height = $parent === null ? 1 : $this->categories->subtreeHeight($category->id());
                $changed = $category->moveUnder($parent, $ancestors, $height) || $changed;
            }

            if ($changed) {
                $this->categories->save($category);
                $this->audit->changed($actor, 'category.updated', 'product_category', $category, $before);
            }

            return $category;
        });
    }
}
