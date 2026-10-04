<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Text\Name;

/**
 * Product category with an optional parent, e.g.
 *
 *   FOOD → EGG, VEGETABLE, COOKING_OIL
 *   PACKAGING → TRASH_BAG, THERMAL_LABEL
 *
 * Deliberately simple: a tree with a bounded depth, no taxonomy engine.
 * Category-specific product attributes (egg grade, oil volume …) are NOT
 * modelled here; see Product for the extension point.
 */
final class ProductCategory extends AggregateRoot
{
    public const MAX_DEPTH = 5;

    private function __construct(
        Id $id,
        int $version,
        private readonly BusinessCode $code,
        private Name $name,
        private ?Id $parentId,
        private bool $active,
    ) {
        parent::__construct($id, $version);
    }

    /**
     * @param  list<Id>  $ancestorsOfParent  ids from the parent up to its root (parent first)
     */
    public static function create(Id $id, BusinessCode $code, Name $name, ?self $parent = null, array $ancestorsOfParent = []): self
    {
        if ($parent !== null) {
            self::assertUsableAsParent($parent);
            self::assertDepth($ancestorsOfParent);
        }

        return new self($id, 0, $code, $name, $parent?->id(), true);
    }

    public static function reconstitute(Id $id, int $version, BusinessCode $code, Name $name, ?Id $parentId, bool $active): self
    {
        return new self($id, $version, $code, $name, $parentId, $active);
    }

    public function rename(Name $name): bool
    {
        if ($this->name->equals($name)) {
            return false;
        }
        $this->name = $name;

        return true;
    }

    /**
     * Move this category under another one (or to the top level with null).
     *
     * @param  list<Id>  $ancestorsOfNewParent  ids from the new parent up to its root (parent first)
     * @param  int  $subtreeHeight  levels in this category's subtree, itself included (1 = leaf)
     */
    public function moveUnder(?self $newParent, array $ancestorsOfNewParent = [], int $subtreeHeight = 1): bool
    {
        if ($newParent === null) {
            $changed = $this->parentId !== null;
            $this->parentId = null;

            return $changed;
        }

        if ($newParent->id()->equals($this->id())) {
            throw InvariantViolation::because('CATEGORY_CYCLE', 'A category cannot be its own parent.');
        }
        foreach ($ancestorsOfNewParent as $ancestor) {
            if ($ancestor->equals($this->id())) {
                throw InvariantViolation::because('CATEGORY_CYCLE', 'A category cannot be moved under one of its own descendants.');
            }
        }
        self::assertDepth($ancestorsOfNewParent, $subtreeHeight);
        self::assertUsableAsParent($newParent);

        $changed = $this->parentId === null || ! $this->parentId->equals($newParent->id());
        $this->parentId = $newParent->id();

        return $changed;
    }

    public function activate(): bool
    {
        if ($this->active) {
            return false;
        }
        $this->active = true;

        return true;
    }

    /**
     * A category that still has active products or active sub-categories
     * cannot be deactivated: those would silently become unclassifiable.
     */
    public function deactivate(int $activeChildCategories, int $activeProducts): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($activeChildCategories > 0 || $activeProducts > 0) {
            throw InvariantViolation::because(
                'CATEGORY_IN_USE',
                'Deactivate or move the active sub-categories and products first.',
                ['active_child_categories' => $activeChildCategories, 'active_products' => $activeProducts],
            );
        }
        $this->active = false;

        return true;
    }

    public function code(): BusinessCode
    {
        return $this->code;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function parentId(): ?Id
    {
        return $this->parentId;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function snapshot(): array
    {
        return [
            'code' => $this->code->value,
            'name' => $this->name->value,
            'parent_id' => $this->parentId?->value,
            'is_active' => $this->active,
        ];
    }

    /**
     * The category sits one level below its parent chain, and its deepest
     * descendant sits ($subtreeHeight - 1) levels below the category. The
     * whole subtree must stay within MAX_DEPTH.
     *
     * @param  list<Id>  $ancestorsOfParent
     */
    private static function assertDepth(array $ancestorsOfParent, int $subtreeHeight = 1): void
    {
        if (count($ancestorsOfParent) + max(1, $subtreeHeight) > self::MAX_DEPTH) {
            throw InvariantViolation::because('CATEGORY_TOO_DEEP', 'Category hierarchy may be at most '.self::MAX_DEPTH.' levels deep.');
        }
    }

    private static function assertUsableAsParent(self $parent): void
    {
        if (! $parent->isActive()) {
            throw InvariantViolation::because('CATEGORY_PARENT_INACTIVE', "Parent category {$parent->code()->value} is inactive.");
        }
    }
}
