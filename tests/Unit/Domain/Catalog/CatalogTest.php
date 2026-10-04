<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductClassification;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Text\Name;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private int $seq = 0;

    private function id(): Id
    {
        return Id::fromString(sprintf('01928a3c-0000-7000-8000-%012d', ++$this->seq));
    }

    private function category(string $code, ?ProductCategory $parent = null, array $ancestors = []): ProductCategory
    {
        return ProductCategory::create($this->id(), BusinessCode::of($code), Name::of($code), $parent, $ancestors);
    }

    private function egg(?ProductCategory $category = null): Product
    {
        return Product::create($this->id(), BusinessCode::of('TLR-A'), Name::of('Telur Ayam Grade A'), $category ?? $this->category('EGG'), Uom::kg());
    }

    // ── category ──

    public function test_category_hierarchy(): void
    {
        $food = $this->category('FOOD');
        $egg = $this->category('EGG', $food, [$food->id()]);

        $this->assertTrue($egg->parentId()?->equals($food->id()));
        $this->assertTrue($egg->isActive());
        $this->assertSame(0, $egg->version());
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $food = $this->category('FOOD');

        $this->expectExceptionMessage('own parent');
        $food->moveUnder($food, [$food->id()]);
    }

    public function test_category_cannot_move_under_its_descendant(): void
    {
        $food = $this->category('FOOD');
        $egg = $this->category('EGG', $food, [$food->id()]);

        try {
            $food->moveUnder($egg, [$egg->id(), $food->id()]);
            $this->fail('cycle must be rejected');
        } catch (InvariantViolation $e) {
            $this->assertSame('CATEGORY_CYCLE', $e->errorCode());
        }
    }

    public function test_category_depth_is_bounded(): void
    {
        $ancestors = [$this->id(), $this->id(), $this->id(), $this->id(), $this->id()];
        $parent = $this->category('L5');

        $this->expectExceptionMessage('at most 5 levels');
        $this->category('L6', $parent, $ancestors);
    }

    public function test_moving_a_subtree_checks_the_deepest_descendant(): void
    {
        $l4 = $this->category('L4');
        $ancestorsOfL4 = [$l4->id(), $this->id(), $this->id(), $this->id()]; // L4 is at level 4
        $a = $this->category('A');

        // leaf A → level 5: allowed
        $this->assertTrue($a->moveUnder($l4, $ancestorsOfL4, subtreeHeight: 1));

        // A with children (height 3) → deepest at level 7: rejected
        $b = $this->category('B');
        $this->expectExceptionMessage('at most 5 levels');
        $b->moveUnder($l4, $ancestorsOfL4, subtreeHeight: 3);
    }

    public function test_inactive_category_cannot_be_parent(): void
    {
        $food = $this->category('FOOD');
        $food->deactivate(0, 0);

        $this->expectExceptionMessage('inactive');
        $this->category('EGG', $food, [$food->id()]);
    }

    public function test_category_in_use_cannot_be_deactivated(): void
    {
        $egg = $this->category('EGG');

        try {
            $egg->deactivate(activeChildCategories: 0, activeProducts: 3);
            $this->fail();
        } catch (InvariantViolation $e) {
            $this->assertSame('CATEGORY_IN_USE', $e->errorCode());
            $this->assertSame(3, $e->context()['active_products']);
        }
        $this->assertTrue($egg->isActive());
    }

    public function test_category_rename_and_move_to_top_level(): void
    {
        $food = $this->category('FOOD');
        $egg = $this->category('EGG', $food, [$food->id()]);

        $this->assertTrue($egg->rename(Name::of('Telur')));
        $this->assertFalse($egg->rename(Name::of('Telur')), 'no-op returns false');
        $this->assertTrue($egg->moveUnder(null));
        $this->assertNull($egg->parentId());
    }

    // ── product ──

    public function test_product_is_created_active_in_its_base_uom(): void
    {
        $p = $this->egg();

        $this->assertSame('TLR-A', $p->sku()->value);
        $this->assertSame('KG', $p->baseUom()->code);
        $this->assertTrue($p->isActive());
    }

    public function test_product_is_not_egg_specific(): void
    {
        $packaging = $this->category('TRASH_BAG');
        $bag = Product::create($this->id(), BusinessCode::of('TB-60X100'), Name::of('Trash Bag 60x100'), $packaging, Uom::pcs(), 'Hitam, isi 10');

        $this->assertSame('PCS', $bag->baseUom()->code);
        $this->assertSame('Hitam, isi 10', $bag->description());
    }

    public function test_product_cannot_be_created_in_inactive_category(): void
    {
        $cat = $this->category('EGG');
        $cat->deactivate(0, 0);

        $this->expectExceptionMessage('inactive');
        $this->egg($cat);
    }

    public function test_rename_recategorize_describe(): void
    {
        $p = $this->egg();
        $other = $this->category('PROTEIN');

        $this->assertTrue($p->rename(Name::of('Telur Ayam Negeri A')));
        $this->assertTrue($p->recategorize($other));
        $this->assertTrue($p->categoryId()->equals($other->id()));
        $this->assertTrue($p->describe('Berat 60–65 g per butir'));
        $this->assertSame('Berat 60–65 g per butir', $p->description());
        $this->assertTrue($p->describe('  '), 'blank clears the description');
        $this->assertNull($p->description());
        $this->assertFalse($p->describe(null), 'already null');
    }

    public function test_product_classification_defaults_to_sellable_and_can_change_before_transaction(): void
    {
        $p = $this->egg();

        $this->assertSame(ProductClassification::SELLABLE, $p->classification());
        $this->assertTrue($p->reclassify(ProductClassification::PACKAGING, usedInTransactions: false));
        $this->assertSame(ProductClassification::PACKAGING, $p->classification());
        $this->assertFalse($p->reclassify(ProductClassification::PACKAGING, usedInTransactions: false));
    }

    public function test_product_classification_locked_after_transactions(): void
    {
        $p = $this->egg();

        try {
            $p->reclassify(ProductClassification::CONSUMABLE, usedInTransactions: true);
            $this->fail();
        } catch (InvariantViolation $e) {
            $this->assertSame('PRODUCT_CLASSIFICATION_LOCKED', $e->errorCode());
        }

        $this->assertSame(ProductClassification::SELLABLE, $p->classification());
    }

    public function test_base_uom_change_allowed_before_any_transaction(): void
    {
        $p = $this->egg();

        $this->assertTrue($p->changeBaseUom(Uom::pcs(), usedInTransactions: false));
        $this->assertSame('PCS', $p->baseUom()->code);
    }

    public function test_base_uom_locked_after_transactions(): void
    {
        $p = $this->egg();

        try {
            $p->changeBaseUom(Uom::pcs(), usedInTransactions: true);
            $this->fail();
        } catch (InvariantViolation $e) {
            $this->assertSame('PRODUCT_UOM_LOCKED', $e->errorCode());
        }
        $this->assertSame('KG', $p->baseUom()->code);
    }

    public function test_activation_is_idempotent_and_reports_change(): void
    {
        $p = $this->egg();

        $this->assertFalse($p->activate());
        $this->assertTrue($p->deactivate());
        $this->assertFalse($p->deactivate());
        $this->assertFalse($p->isActive());
        $this->assertTrue($p->activate());
    }

    public function test_snapshot_for_audit(): void
    {
        $this->assertSame(
            ['sku' => 'TLR-A', 'base_uom' => 'KG', 'classification' => 'SELLABLE', 'is_active' => true],
            array_intersect_key($this->egg()->snapshot(), array_flip(['sku', 'base_uom', 'classification', 'is_active'])),
        );
    }
}
