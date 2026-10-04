<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Inventory\LotRepository;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockMovement;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * Compensates every not-yet-reversed movement of one source document with a
 * REVERSAL movement (opposite direction, same position, lot and quantity), newest
 * first, under one new correlation id. Used by goods-receipt reversal (A3)
 * and sale reversal (A4). Must run inside the caller's transaction.
 *
 * `reverses_movement_id` is UNIQUE in the database, so even a buggy caller
 * cannot reverse a movement twice; the no-negative-stock rule applies as for
 * any posting (stock already used → INSUFFICIENT_STOCK, nothing written).
 */
final class LedgerReversal
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryPoster $inventory,
        private readonly LotRepository $lots,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<StockMovement> the reversal movements written */
    public function reverseSource(string $sourceType, Id $sourceId, ?string $actorUserId, string $reason): array
    {
        $correlation = $this->ids->next();
        $originals = array_reverse(array_values(array_filter(
            $this->ledger->movementsOfSource($sourceType, $sourceId),
            fn (StockMovement $m) => $m->type !== MovementType::REVERSAL && ! $this->ledger->isReversed($m->id),
        )));
        $reversals = array_map(
            fn (StockMovement $m) => $m->reverse($this->ids->next(), $this->clock->today(), $this->clock->now(),
                new SourceDocument($sourceType, $sourceId, $m->source->lineId), $correlation, $actorUserId, $reason,
                // historical (pre-lot) movements are reversed into their LEGACY lot
                $m->lotId === null ? $this->lots->legacyLotOf($m->key->productId, $m->key->nodeId) : null),
            $originals,
        );
        if ($reversals !== []) {
            $this->inventory->post($reversals);
        }

        return $reversals;
    }
}
