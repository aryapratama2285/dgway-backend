<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;

/**
 * Read side of inventory. Results are plain arrays (read models), not
 * aggregates: balances and movement lists are reporting views.
 */
interface InventoryQueries
{
    /**
     * Current stock of one product at one node, per bucket, plus the
     * ledger-derived total for cross-checking.
     *
     * @return array{product_id: string, node_id: string, uom: string, available: string, quarantine: string, return_pending: string, on_hand: string, ledger_on_hand: string}
     */
    public function currentStock(string $productId, string $nodeId): array;

    /** @return Page<array<string, mixed>> */
    public function balances(?string $nodeId, ?string $productId, ?string $bucket, bool $includeZero, PageRequest $page): Page;

    /** @return Page<array<string, mixed>> */
    public function movements(array $filters, PageRequest $page): Page;
}
