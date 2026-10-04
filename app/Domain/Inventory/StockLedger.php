<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Uom;

/**
 * Persistence port for the inventory ledger and its balance projection.
 *
 * Implementations must:
 * - write movements append-only;
 * - lock balance rows (SELECT … FOR UPDATE) so concurrent postings to the
 *   same position serialise;
 * - run inside the caller's transaction, so movement and balance commit or
 *   roll back together.
 */
interface StockLedger
{
    /**
     * Lock the given positions (creating zero rows when missing) in a
     * deterministic order and return their current balances, keyed by
     * StockKey::toString().
     *
     * @param  list<StockKey>  $keys
     * @param  array<string, Uom>  $uomByProduct  base UOM per product id
     * @return array<string, StockBalance>
     */
    public function lockBalances(array $keys, array $uomByProduct): array;

    public function append(StockMovement $movement): void;

    public function saveBalance(StockBalance $balance): void;

    /** @return list<StockMovement> movements caused by a source document, in ledger order */
    public function movementsOfSource(string $sourceType, Id $sourceId): array;

    public function isReversed(Id $movementId): bool;

    /**
     * Lock lot positions (creating zero rows when missing) in key order and
     * return their balances keyed by LotBalanceKey::toString(). Callers lock
     * the stock positions first (lockBalances), then the lots.
     *
     * @param  list<LotBalanceKey>  $keys
     * @param  array<string, Uom>  $uomByProduct
     * @return array<string, LotBalance>
     */
    public function lockLotBalances(array $keys, array $uomByProduct): array;

    public function saveLotBalance(LotBalance $balance): void;

    /**
     * Lots holding stock in a position, locked FOR UPDATE. Call only while
     * holding the position lock (lockBalances), which serialises allocation.
     *
     * @return list<LotStock>
     */
    public function lotsInPosition(StockKey $position, Uom $uom): array;
}
