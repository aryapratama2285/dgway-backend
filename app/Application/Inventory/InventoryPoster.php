<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Domain\Catalog\ProductRepository;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockPosting;
use App\Domain\Shared\Identity\Id;

/**
 * Application entry point to the ledger: resolves base UOMs for the products
 * involved and hands the batch to the StockPosting domain service.
 * Must be called inside the use case's transaction.
 */
final class InventoryPoster
{
    public function __construct(
        private readonly StockPosting $posting,
        private readonly ProductRepository $products,
    ) {
    }

    /** @param list<StockMovement> $movements */
    public function post(array $movements): void
    {
        $uoms = [];
        foreach ($movements as $m) {
            $pid = $m->key->productId->value;
            // Historical products may be inactive (e.g. during a reversal); read them anyway.
            $uoms[$pid] ??= $this->products->get(Id::fromString($pid))->baseUom();
        }

        $this->posting->post($movements, $uoms);
    }

    /**
     * Movement summary for audit entries.
     *
     * @param  list<StockMovement>  $movements
     * @return list<array<string, string>>
     */
    public static function summarize(array $movements): array
    {
        return array_map(fn (StockMovement $m) => [
            'type' => $m->type->value,
            'product_id' => $m->key->productId->value,
            'bucket' => $m->key->bucket->value,
            'qty' => $m->signedQuantity()->toStorage(),
        ], $movements);
    }
}
