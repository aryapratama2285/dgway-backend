<?php

declare(strict_types=1);

namespace App\Application\Inventory;

/**
 * Maintenance port for the stock_balances projection.
 *
 * verify()  compares every position's projected quantity with the ledger sum.
 * rebuild() recomputes all positions from the ledger (inside one transaction,
 *           holding a lock that blocks concurrent postings meanwhile).
 */
interface StockProjection
{
    /** @return list<array{product_id: string, node_id: string, bucket: string, projected: string, ledger: string}> mismatches */
    public function verify(): array;

    /** @return int number of positions written */
    public function rebuild(): int;
}
