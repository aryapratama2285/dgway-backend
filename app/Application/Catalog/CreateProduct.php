<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class CreateProduct
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductCategoryRepository $categories,
        private readonly UomResolver $uoms,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $sku, string $name, string $categoryId, string $baseUom, ?string $description = null): Product
    {
        return $this->tx->run(function () use ($actor, $sku, $name, $categoryId, $baseUom, $description): Product {
            $product = Product::create(
                $this->ids->next(),
                BusinessCode::of($sku, 'SKU'),
                Name::of($name, 'Product name'),
                $this->categories->get(Id::fromString($categoryId)),
                $this->uoms->activeOrFail($baseUom),
                $description,
            );

            $this->products->add($product);
            $this->audit->created($actor, 'product.created', 'product', $product);

            return $product;
        });
    }
}
