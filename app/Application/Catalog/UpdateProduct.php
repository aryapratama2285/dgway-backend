<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductClassification;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductUomConversionRepository;
use App\Domain\Inventory\ReorderPointRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

/**
 * Applies the business changes a PATCH carries, each through its own intent
 * method on the aggregate: rename, recategorize, describe, changeBaseUom.
 * The SKU is immutable.
 */
final class UpdateProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly UomResolver $uoms,
        private readonly ProductUsageChecker $usage,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly ReorderPointRepository $reorderPoints,
        private readonly ProductUomConversionRepository $conversions,
    ) {
    }

    public function execute(Actor $actor, string $productId, Patch $patch, ?int $expectedVersion = null): Product
    {
        return $this->tx->run(function () use ($actor, $productId, $patch, $expectedVersion): Product {
            $product = $this->products->getForUpdate(Id::fromString($productId));
            ExpectedVersion::check('Product', $product, $expectedVersion);
            $before = $product->snapshot();
            $changed = false;

            if ($patch->has('name')) {
                $changed = $product->rename(Name::of((string) $patch->string('name'), 'Product name')) || $changed;
            }
            if ($patch->has('category_id')) {
                $changed = $product->recategorize($this->categories->get(Id::fromString((string) $patch->string('category_id')))) || $changed;
            }
            if ($patch->has('description')) {
                $changed = $product->describe($patch->string('description')) || $changed;
            }
            if ($patch->has('base_uom')) {
                $uom = $this->uoms->activeOrFail((string) $patch->string('base_uom'));
                // F3: a reorder point is expressed in the base UOM; changing the unit
                // would silently change what the threshold means.
                if ($uom->code !== $product->baseUom()->code && $this->reorderPoints->hasActiveFor($product->id())) {
                    throw InvariantViolation::because(
                        'BASE_UOM_LOCKED_BY_REORDER_POINT',
                        "{$product->sku()->value} has an active reorder point in {$product->baseUom()->code}. Clear it before changing the base UOM.",
                        ['field' => 'base_uom'],
                    );
                }
                // F4A: a purchasing-unit factor is "base units per unit"; changing the base would change its meaning.
                if ($uom->code !== $product->baseUom()->code && $this->conversions->hasActiveFor($product->id())) {
                    throw InvariantViolation::because(
                        'PRODUCT_UOM_LOCKED',
                        "{$product->sku()->value} has active purchasing-unit conversions to {$product->baseUom()->code}. Deactivate them before changing the base UOM.",
                        ['field' => 'base_uom'],
                    );
                }
                $changed = $product->changeBaseUom($uom, $this->usage->isUsedInTransactions($product->id())) || $changed;
            }
            if ($patch->has('classification')) {
                $classification = ProductClassification::from((string) $patch->string('classification'));
                $changed = $product->reclassify(
                    $classification,
                    $this->usage->isUsedInTransactions($product->id()),
                ) || $changed;
            }

            if ($changed) {
                $this->products->save($product);
                $this->audit->changed($actor, 'product.updated', 'product', $product, $before);
            }

            return $product;
        });
    }
}
