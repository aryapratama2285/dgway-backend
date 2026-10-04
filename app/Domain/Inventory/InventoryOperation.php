<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * A stock operation as a document (number INV-YYMMDD-NNN): who, when, why,
 * what. It is the source document of the movements it produces, so every
 * loss, regrade, recovery, supplier return and adjustment is traceable.
 *
 * Immutable once created; corrections are new operations.
 */
final class InventoryOperation
{
    private function __construct(
        public readonly Id $id,
        public readonly DocumentNumber $number,
        public readonly InventoryOperationType $type,
        public readonly Id $nodeId,
        public readonly Id $productId,
        public readonly Quantity $quantity,
        public readonly StockBucket $fromBucket,
        public readonly ?Id $targetProductId,
        public readonly ?Direction $adjustmentDirection,
        public readonly string $reason,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    public static function loss(Id $id, DocumentNumber $no, Id $nodeId, Id $productId, Quantity $qty, string $reason, BusinessDate $date, DateTimeImmutable $at, StockBucket $from = StockBucket::AVAILABLE): self
    {
        return self::make($id, $no, InventoryOperationType::LOSS, $nodeId, $productId, $qty, $from, null, null, $reason, $date, $at);
    }

    /** Regrade between two products that share the same base UOM (no implicit conversion). */
    public static function regrade(Id $id, DocumentNumber $no, Id $nodeId, Id $fromProductId, Id $toProductId, Quantity $qty, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        if ($fromProductId->equals($toProductId)) {
            throw InvariantViolation::because('REGRADE_TO_SAME_PRODUCT', 'A regrade must change the product.');
        }

        return self::make($id, $no, InventoryOperationType::REGRADE, $nodeId, $fromProductId, $qty, StockBucket::AVAILABLE, $toProductId, null, $reason, $date, $at);
    }

    public static function recovery(Id $id, DocumentNumber $no, Id $nodeId, Id $productId, Quantity $qty, StockBucket $from, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        if ($from->isSellable()) {
            throw InvariantViolation::because('RECOVERY_FROM_SELLABLE', 'Recovery moves non-sellable stock (RETURN_PENDING or QUARANTINE) back to AVAILABLE.');
        }

        return self::make($id, $no, InventoryOperationType::RECOVERY, $nodeId, $productId, $qty, $from, null, null, $reason, $date, $at);
    }

    public static function supplierReturn(Id $id, DocumentNumber $no, Id $nodeId, Id $productId, Quantity $qty, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        return self::make($id, $no, InventoryOperationType::SUPPLIER_RETURN, $nodeId, $productId, $qty, StockBucket::RETURN_PENDING, null, null, $reason, $date, $at);
    }

    public static function adjustment(Id $id, DocumentNumber $no, Id $nodeId, Id $productId, Quantity $qty, Direction $direction, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        return self::make($id, $no, InventoryOperationType::ADJUSTMENT, $nodeId, $productId, $qty, StockBucket::AVAILABLE, null, $direction, $reason, $date, $at);
    }

    public static function reconstitute(Id $id, DocumentNumber $no, InventoryOperationType $type, Id $nodeId, Id $productId, Quantity $qty, StockBucket $from, ?Id $target, ?Direction $dir, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        return new self($id, $no, $type, $nodeId, $productId, $qty, $from, $target, $dir, $reason, $date, $at);
    }

    /**
     * Does the quantity come out of existing lots (then the application
     * allocates it FIFO under lock), or does it create a new lot?
     */
    public function consumesLots(): bool
    {
        return ! ($this->type === InventoryOperationType::ADJUSTMENT && $this->adjustmentDirection === Direction::IN);
    }

    /** The position the quantity is taken from (when consumesLots()). */
    public function sourcePosition(): StockKey
    {
        return new StockKey($this->productId, $this->nodeId, $this->fromBucket);
    }

    /**
     * The ledger movements this operation produces, all sharing one
     * correlation id, one set per lot portion:
     *
     *   LOSS             LOSS −q (bucket)                         same lot
     *   SUPPLIER_RETURN  SUPPLIER_RETURN −q RETURN_PENDING          same lot
     *   ADJUSTMENT OUT   ADJUSTMENT_OUT −q AVAILABLE                same lot
     *   RECOVERY         RECOVERY −q (bucket), RECOVERY +q AVAILABLE same lot (only the bucket changes)
     *   REGRADE          REGRADE_OUT −q lot L (product A), REGRADE_IN +q derived lot of L (product B)
     *   ADJUSTMENT IN    ADJUSTMENT_IN +q into the new ADJUSTMENT lot
     *
     * @param  callable(): Id  $nextId
     * @param  list<LotPortion>  $portions  where the quantity comes from; Σ = quantity
     * @param  array<string, Id>  $derivedLots  parent lot id → derived lot id (REGRADE)
     * @param  ?Id  $newLotId  the lot created for an ADJUSTMENT IN
     * @return list<StockMovement>
     */
    public function movements(callable $nextId, ?string $actorUserId, array $portions = [], array $derivedLots = [], ?Id $newLotId = null): array
    {
        $src = new SourceDocument('inventory_operation', $this->id);
        $mk = fn (MovementType $t, StockKey $k, Direction $d, Quantity $q, Id $lot) => StockMovement::record(
            $nextId(), $t, $k, $d, $q, $this->businessDate, $this->occurredAt, $src, $this->id, $actorUserId, $this->reason, null, null, $lot,
        );

        if (! $this->consumesLots()) {
            return [$mk(MovementType::ADJUSTMENT_IN, $this->sourcePosition(), Direction::IN, $this->quantity,
                $newLotId ?? throw InvariantViolation::because('LOT_REQUIRED', 'An adjustment IN creates a new lot.'))];
        }

        $total = Quantity::zero($this->quantity->uom);
        foreach ($portions as $p) {
            $total = $total->plus($p->quantity);
        }
        if (! $total->equals($this->quantity)) {
            throw InvariantViolation::because('LOT_ALLOCATION_MISMATCH', "Lot portions total {$total->toStorage()}, operation is {$this->quantity->toStorage()}.");
        }

        $from = $this->sourcePosition();
        $available = new StockKey($this->productId, $this->nodeId, StockBucket::AVAILABLE);
        $out = [];
        foreach ($portions as $p) {
            switch ($this->type) {
                case InventoryOperationType::LOSS:
                    $out[] = $mk(MovementType::LOSS, $from, Direction::OUT, $p->quantity, $p->lotId);
                    break;
                case InventoryOperationType::SUPPLIER_RETURN:
                    $out[] = $mk(MovementType::SUPPLIER_RETURN, $from, Direction::OUT, $p->quantity, $p->lotId);
                    break;
                case InventoryOperationType::ADJUSTMENT:
                    $out[] = $mk(MovementType::ADJUSTMENT_OUT, $from, Direction::OUT, $p->quantity, $p->lotId);
                    break;
                case InventoryOperationType::RECOVERY:
                    $out[] = $mk(MovementType::RECOVERY, $from, Direction::OUT, $p->quantity, $p->lotId);
                    $out[] = $mk(MovementType::RECOVERY, $available, Direction::IN, $p->quantity, $p->lotId);
                    break;
                case InventoryOperationType::REGRADE:
                    $target = new StockKey($this->targetProductId ?? throw InvariantViolation::because('REGRADE_TARGET_REQUIRED', 'Regrade needs a target product.'), $this->nodeId, StockBucket::AVAILABLE);
                    $derived = $derivedLots[$p->lotId->value] ?? throw InvariantViolation::because('LOT_REQUIRED', 'Every regraded lot portion needs its derived lot.');
                    $out[] = $mk(MovementType::REGRADE_OUT, $available, Direction::OUT, $p->quantity, $p->lotId);
                    $out[] = $mk(MovementType::REGRADE_IN, $target, Direction::IN, $p->quantity, $derived);
                    break;
            }
        }

        return $out;
    }

    private static function make(Id $id, DocumentNumber $no, InventoryOperationType $type, Id $nodeId, Id $productId, Quantity $qty, StockBucket $from, ?Id $target, ?Direction $dir, string $reason, BusinessDate $date, DateTimeImmutable $at): self
    {
        $qty->assertPositive('Quantity');
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('REASON_REQUIRED', 'Every inventory operation needs a reason.');
        }

        return new self($id, $no, $type, $nodeId, $productId, $qty, $from, $target, $dir, mb_substr($reason, 0, 500), $date, $at);
    }
}
