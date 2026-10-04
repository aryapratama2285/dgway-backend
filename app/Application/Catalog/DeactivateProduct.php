<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class DeactivateProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $productId): Product
    {
        return $this->tx->run(function () use ($actor, $productId): Product {
            $product = $this->products->getForUpdate(Id::fromString($productId));
            $before = $product->snapshot();

            if ($product->deactivate()) {
                $this->products->save($product);
                $this->audit->changed($actor, 'product.deactivated', 'product', $product, $before);
            }

            return $product;
        });
    }
}
