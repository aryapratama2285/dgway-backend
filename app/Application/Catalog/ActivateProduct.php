<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class ActivateProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $productId): Product
    {
        return $this->tx->run(function () use ($actor, $productId): Product {
            $product = $this->products->getForUpdate(Id::fromString($productId));
            if (! $product->isActive() && ! $this->categories->get($product->categoryId())->isActive()) {
                throw InvariantViolation::because('CATEGORY_INACTIVE', 'Activate the product category first, or move the product to an active category.');
            }

            $before = $product->snapshot();
            if ($product->activate()) {
                $this->products->save($product);
                $this->audit->changed($actor, 'product.activated', 'product', $product, $before);
            }

            return $product;
        });
    }
}
