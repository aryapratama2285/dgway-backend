<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Uom;

/**
 * Domain service: posts a batch of movements that belong to one business
 * event, keeping every touched balance consistent with the ledger.
 *
 * A service rather than an aggregate method because one event (a receipt
 * with ACCEPT + LOSS + REGRADE) touches several stock positions.
 *
 * Steps (all inside the caller's transaction):
 *   1. lock every touched position in a deterministic order (no deadlocks
 *      between two postings touching the same positions);
 *   2. apply the movements in the given order to the locked balances —
 *      the NegativeStockPolicy is checked on every intermediate state;
 *   3. lock the lot positions (A5-B) in key order — always AFTER the stock
 *      positions, so the lock order is the same everywhere — and apply the
 *      movements to them too (no lot may go negative either);
 *   4. append the movements and save all balances.
 * If any step fails nothing is written (the transaction rolls back).
 */
final class StockPosting
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly NegativeStockPolicy $policy,
    ) {
    }

    /**
     * @param  list<StockMovement>  $movements
     * @param  array<string, Uom>  $uomByProduct  base UOM per product id
     * @return array<string, StockBalance> resulting balances keyed by position
     */
    public function post(array $movements, array $uomByProduct): array
    {
        if ($movements === []) {
            throw InvariantViolation::because('NO_MOVEMENTS', 'Nothing to post.');
        }

        $keys = [];
        foreach ($movements as $m) {
            $uom = $uomByProduct[$m->key->productId->value] ?? null;
            if ($uom === null || ! $uom->equals($m->quantity->uom)) {
                throw InvariantViolation::because('MOVEMENT_UOM_NOT_BASE', 'Movements must be in the product base UOM.');
            }
            $keys[$m->key->toString()] = $m->key;
        }

        $balances = $this->ledger->lockBalances(array_values($keys), $uomByProduct);

        $lotKeys = [];
        foreach ($movements as $m) {
            if ($m->lotId === null) {
                throw InvariantViolation::because('MOVEMENT_LOT_REQUIRED', 'Every new stock movement must name its lot.');
            }
            $lotKeys[LotBalanceKey::of($m)->toString()] = LotBalanceKey::of($m);
        }
        $lotBalances = $this->ledger->lockLotBalances(array_values($lotKeys), $uomByProduct);

        foreach ($movements as $m) {
            $balances[$m->key->toString()]->apply($m, $this->policy);
            $lotBalances[LotBalanceKey::of($m)->toString()]->apply($m, $this->policy);
        }
        foreach ($movements as $m) {
            $this->ledger->append($m);
        }
        foreach ($balances as $balance) {
            $this->ledger->saveBalance($balance);
        }
        foreach ($lotBalances as $balance) {
            $this->ledger->saveLotBalance($balance);
        }

        return $balances;
    }
}
