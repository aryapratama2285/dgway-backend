<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\StockBucket;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockMovement;
use App\Domain\Procurement\DispositionType;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Shared\Identity\Id;
use DateTimeImmutable;

/**
 * Translates a posted goods receipt (Procurement language) into inventory
 * ledger movements (Inventory language). Lives in the application layer so
 * the two domain contexts never import each other.
 *
 * Rule: EVERYTHING physically received enters the ledger as RECEIPT, into
 * the bucket its disposition decides. Then:
 *
 *   ACCEPT q              RECEIPT +q AVAILABLE
 *   LOSS q                RECEIPT +q QUARANTINE, LOSS −q QUARANTINE          (net 0, traced)
 *   RETURN_TO_SUPPLIER q  RECEIPT +q RETURN_PENDING                         (held, not sellable)
 *   REGRADE q → B         RECEIPT +q QUARANTINE, REGRADE_OUT −q QUARANTINE,
 *                         REGRADE_IN +q AVAILABLE of product B             (conserved)
 *
 * So Σ RECEIPT of a line = received quantity — mass balance holds in the
 * ledger itself, not only on the document. Every movement carries the line's
 * unit cost, the receipt as source/line, and the receipt id as correlation.
 *
 * Lots (A5-B): every movement of a line carries the line's lot, so the lot's
 * original quantity (= received) and its disposition consequences are traced;
 * a REGRADE_IN goes into the derived lot of the target product (parent = the
 * line's lot).
 */
final class ReceiptMovementPlanner
{
    public function __construct(private readonly IdentityGenerator $ids)
    {
    }

    /**
     * @param  array<string, Id>  $lotByLine  goods-receipt line id → its lot
     * @param  array<string, Id>  $derivedLots  "lineId|targetProductId" → derived lot (REGRADE)
     * @return list<StockMovement>
     */
    public function plan(GoodsReceipt $gr, DateTimeImmutable $occurredAt, ?string $actorUserId, array $lotByLine, array $derivedLots = []): array
    {
        $movements = [];
        foreach ($gr->lines() as $line) {
            $source = new SourceDocument('goods_receipt', $gr->id(), $line->id);
            $lot = $lotByLine[$line->id->value];
            $mk = fn (MovementType $type, StockKey $key, Direction $dir, $qty, ?string $reason = null, ?Id $lotId = null) => StockMovement::record(
                $this->ids->next(), $type, $key, $dir, $qty, $gr->businessDate, $occurredAt, $source, $gr->id(), $actorUserId, $reason, $line->unitCost, null, $lotId ?? $lot,
            );
            $at = fn (StockBucket $b, $productId = null) => new StockKey($productId ?? $line->productId, $gr->nodeId, $b);

            foreach ($line->dispositions as $d) {
                switch ($d->type) {
                    case DispositionType::ACCEPT:
                        $movements[] = $mk(MovementType::RECEIPT, $at(StockBucket::AVAILABLE), Direction::IN, $d->quantity, $d->reason);
                        break;
                    case DispositionType::LOSS:
                        $movements[] = $mk(MovementType::RECEIPT, $at(StockBucket::QUARANTINE), Direction::IN, $d->quantity, $d->reason);
                        $movements[] = $mk(MovementType::LOSS, $at(StockBucket::QUARANTINE), Direction::OUT, $d->quantity, $d->reason ?? 'loss at receiving');
                        break;
                    case DispositionType::RETURN_TO_SUPPLIER:
                        $movements[] = $mk(MovementType::RECEIPT, $at(StockBucket::RETURN_PENDING), Direction::IN, $d->quantity, $d->reason ?? 'to be returned to supplier');
                        break;
                    case DispositionType::REGRADE:
                        $movements[] = $mk(MovementType::RECEIPT, $at(StockBucket::QUARANTINE), Direction::IN, $d->quantity, $d->reason);
                        $movements[] = $mk(MovementType::REGRADE_OUT, $at(StockBucket::QUARANTINE), Direction::OUT, $d->quantity, $d->reason ?? 'regrade at receiving');
                        $movements[] = $mk(MovementType::REGRADE_IN, $at(StockBucket::AVAILABLE, $d->targetProductId), Direction::IN, $d->quantity, $d->reason ?? 'regrade at receiving',
                            $derivedLots[$line->id->value.'|'.$d->targetProductId->value]);
                        break;
                    case DispositionType::RECOVERY:
                        // Not a receiving disposition; Disposition::of() never lets it in.
                        break;
                }
            }
        }

        return $movements;
    }
}
