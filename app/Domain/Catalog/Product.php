<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Text\Name;

/**
 * A sellable / purchasable item identified by a unique SKU.
 *
 * In the MVP one Product = one SKU. Differences that matter commercially
 * (e.g. "Telur Ayam Grade A" vs "Grade B") are separate SKUs, which keeps
 * stock, price and HPP per SKU unambiguous.
 *
 * What is intentionally NOT on Product:
 * - category-specific attributes (egg grade/colour, oil volume, …).
 *   Extension point: a future `product_attributes` / specification table
 *   keyed by product, defined per category. The core Product stays generic.
 * - prices. Purchase and selling prices change daily and need history;
 *   they belong to pricing / purchasing (A3+), not to master data.
 * - UOM conversions. Extension point: product-specific conversions
 *   (e.g. this SKU: 1 TRAY = 30 PCS) in a future `product_uom_conversions`
 *   table. Never a global "TRAY = 30" rule. Not needed while eggs are
 *   stocked and sold in KG (decision D1).
 */
final class Product extends AggregateRoot
{
    public const DESCRIPTION_MAX = 1000;

    private function __construct(
        Id $id,
        int $version,
        private readonly BusinessCode $sku,
        private Name $name,
        private Id $categoryId,
        private Uom $baseUom,
        private ?string $description,
        private bool $active,
    ) {
        parent::__construct($id, $version);
    }

    public static function create(Id $id, BusinessCode $sku, Name $name, ProductCategory $category, Uom $baseUom, ?string $description = null): self
    {
        self::assertCategoryUsable($category);

        return new self($id, 0, $sku, $name, $category->id(), $baseUom, self::cleanDescription($description), true);
    }

    public static function reconstitute(Id $id, int $version, BusinessCode $sku, Name $name, Id $categoryId, Uom $baseUom, ?string $description, bool $active): self
    {
        return new self($id, $version, $sku, $name, $categoryId, $baseUom, $description, $active);
    }

    public function rename(Name $name): bool
    {
        if ($this->name->equals($name)) {
            return false;
        }
        $this->name = $name;

        return true;
    }

    public function recategorize(ProductCategory $category): bool
    {
        if ($this->categoryId->equals($category->id())) {
            return false;
        }
        self::assertCategoryUsable($category);
        $this->categoryId = $category->id();

        return true;
    }

    public function describe(?string $description): bool
    {
        $description = self::cleanDescription($description);
        if ($this->description === $description) {
            return false;
        }
        $this->description = $description;

        return true;
    }

    /**
     * The base UOM may only change while the product has never been used in a
     * transaction: stock, purchase and sale quantities recorded in the old
     * unit would otherwise be silently reinterpreted.
     */
    public function changeBaseUom(Uom $uom, bool $usedInTransactions): bool
    {
        if ($this->baseUom->equals($uom)) {
            return false;
        }
        if ($usedInTransactions) {
            throw InvariantViolation::because(
                'PRODUCT_UOM_LOCKED',
                "Base UOM of {$this->sku->value} cannot change after it has been used in transactions. Create a new SKU instead.",
            );
        }
        $this->baseUom = $uom;

        return true;
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
     * Deactivation hides the SKU from new transactions. Historical purchases,
     * stock and sales keep referencing it (it is never deleted).
     */
    public function deactivate(): bool
    {
        if (! $this->active) {
            return false;
        }
        $this->active = false;

        return true;
    }

    public function sku(): BusinessCode
    {
        return $this->sku;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function categoryId(): Id
    {
        return $this->categoryId;
    }

    public function baseUom(): Uom
    {
        return $this->baseUom;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function snapshot(): array
    {
        return [
            'sku' => $this->sku->value,
            'name' => $this->name->value,
            'category_id' => $this->categoryId->value,
            'base_uom' => $this->baseUom->code,
            'description' => $this->description,
            'is_active' => $this->active,
        ];
    }

    private static function assertCategoryUsable(ProductCategory $category): void
    {
        if (! $category->isActive()) {
            throw InvariantViolation::because('CATEGORY_INACTIVE', "Category {$category->code()->value} is inactive.");
        }
    }

    private static function cleanDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw InvariantViolation::because('DESCRIPTION_TOO_LONG', 'Description must be at most '.self::DESCRIPTION_MAX.' characters.', ['field' => 'description']);
        }

        return $description;
    }
}
