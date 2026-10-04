<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;

/**
 * Read side of inventory lots (A5-B). Plain arrays (read models). Every list
 * is paginated and assembled with a fixed number of queries (no N+1).
 * Lot status is DERIVED from the ledger projection: ACTIVE while any bucket
 * holds stock, DEPLETED otherwise. Nothing here writes.
 */
interface LotQueries
{
    /**
     * @param  array{q?: ?string, product_id?: ?string, node_id?: ?string, supplier_id?: ?string, origin?: ?string, status?: ?string, date_from?: ?string, date_to?: ?string, goods_receipt_id?: ?string}  $filters
     * @return Page<array<string, mixed>>
     */
    public function lots(array $filters, PageRequest $page): Page;

    /** Lot identity, provenance (supplier, receipt, dispositions), remaining per bucket, parent/children. */
    public function lot(string $lotIdOrNumber): array;

    /** @return Page<array<string, mixed>> movements of the lot, newest first */
    public function movements(string $lotId, PageRequest $page): Page;

    /** @return array{lot_id: string, ancestors: list<array<string, mixed>>, descendants: list<array<string, mixed>>} */
    public function lineage(string $lotId): array;

    /**
     * Where the lot went: per sale line, sold / reversed / returned / net quantity and the customer.
     *
     * @return Page<array<string, mixed>>
     */
    public function destinations(string $lotId, PageRequest $page): Page;

    /**
     * Remaining stock per lot (FIFO order), the lot-level view of inventory/balances.
     *
     * @return Page<array<string, mixed>>
     */
    public function balances(?string $nodeId, ?string $productId, ?string $bucket, bool $includeZero, PageRequest $page): Page;
}
