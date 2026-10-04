<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * A batch/lot: a quantity of one product that entered DG Way through one
 * identifiable event, traced through the ledger for its whole life.
 *
 * Immutable identity and provenance (append-only row). Everything that
 * changes over time — remaining quantity per bucket, status — is derived from
 * the ledger (stock_movements.lot_id) and its projection, never stored here.
 *
 * Lineage: a REGRADE lot has exactly one parent (the lot it was taken from),
 * so lineage is a tree; parents exist before children and rows never change,
 * so a cycle cannot be formed.
 */
final class InventoryLot extends AggregateRoot
{
    private function __construct(
        Id $id,
        public readonly DocumentNumber $number,
        public readonly LotOrigin $origin,
        public readonly Id $productId,
        public readonly Id $nodeId,
        public readonly ?Quantity $originalQuantity,
        public readonly ?Id $parentLotId,
        public readonly ?Id $supplierId,
        public readonly ?Id $goodsReceiptId,
        public readonly ?Id $goodsReceiptLineId,
        public readonly SourceDocument $source,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $receivedAt,
        public readonly ?BusinessDate $manufacturedDate,
        public readonly ?BusinessDate $bestBeforeDate,
        public readonly ?BusinessDate $collectionDate = null,
        public readonly ?string $supplierReference = null,
    ) {
        parent::__construct($id, 1);
    }

    /** One lot per posted goods-receipt line: the whole received quantity, whatever its disposition. */
    public static function fromReceipt(Id $id, DocumentNumber $number, Id $productId, Id $nodeId, Quantity $received, Id $supplierId,
        Id $goodsReceiptId, Id $goodsReceiptLineId, BusinessDate $date, DateTimeImmutable $receivedAt,
        ?BusinessDate $manufactured = null, ?BusinessDate $bestBefore = null, ?BusinessDate $collectionDate = null, ?string $supplierReference = null): self
    {
        $received->assertPositive('Lot quantity');
        self::assertDates($date, $manufactured, $bestBefore, $collectionDate);

        // A DG Way lot is DG Way's own identity for what arrived; upstream facts (collection date,
        // supplier reference) are copied from the receipt line exactly as recorded — null stays null.
        return new self($id, $number, LotOrigin::RECEIPT, $productId, $nodeId, $received, null, $supplierId, $goodsReceiptId, $goodsReceiptLineId,
            new SourceDocument('goods_receipt', $goodsReceiptId, $goodsReceiptLineId), $date, $receivedAt, $manufactured, $bestBefore, $collectionDate, $supplierReference);
    }

    /**
     * Part of $parent became another product (regrade). The child keeps the
     * root supplier as provenance and inherits the parent's upstream facts
     * (dates, collection date, supplier reference): they are the same goods.
     */
    public static function derivedFrom(self $parent, Id $id, DocumentNumber $number, Id $productId, Quantity $quantity, SourceDocument $source,
        BusinessDate $date, DateTimeImmutable $at): self
    {
        if ($parent->productId->equals($productId)) {
            throw InvariantViolation::because('LOT_LINEAGE_INVALID', 'A derived lot must change the product.');
        }
        $quantity->assertPositive('Lot quantity');

        return new self($id, $number, LotOrigin::REGRADE, $productId, $parent->nodeId, $quantity, $parent->id(), $parent->supplierId, null, null,
            $source, $date, $at, $parent->manufacturedDate, $parent->bestBeforeDate, $parent->collectionDate, $parent->supplierReference);
    }

    /** Surplus found by a stock count (ADJUSTMENT IN) gets its own lot — no supplier is invented. */
    public static function fromAdjustment(Id $id, DocumentNumber $number, Id $productId, Id $nodeId, Quantity $quantity, Id $operationId,
        BusinessDate $date, DateTimeImmutable $at): self
    {
        $quantity->assertPositive('Lot quantity');

        return new self($id, $number, LotOrigin::ADJUSTMENT, $productId, $nodeId, $quantity, null, null, null, null,
            new SourceDocument('inventory_operation', $operationId), $date, $at, null, null, null, null);
    }

    public static function reconstitute(Id $id, DocumentNumber $number, LotOrigin $origin, Id $productId, Id $nodeId, ?Quantity $original,
        ?Id $parentLotId, ?Id $supplierId, ?Id $goodsReceiptId, ?Id $goodsReceiptLineId, SourceDocument $source, BusinessDate $date,
        DateTimeImmutable $receivedAt, ?BusinessDate $manufactured, ?BusinessDate $bestBefore, ?BusinessDate $collectionDate = null, ?string $supplierReference = null): self
    {
        return new self($id, $number, $origin, $productId, $nodeId, $original, $parentLotId, $supplierId, $goodsReceiptId, $goodsReceiptLineId,
            $source, $date, $receivedAt, $manufactured, $bestBefore, $collectionDate, $supplierReference);
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'origin' => $this->origin->value,
            'product_id' => $this->productId->value,
            'node_id' => $this->nodeId->value,
            'original_quantity' => $this->originalQuantity?->toStorage(),
            'parent_lot_id' => $this->parentLotId?->value,
            'supplier_id' => $this->supplierId?->value,
            'goods_receipt_id' => $this->goodsReceiptId?->value,
            'source' => $this->source->type.':'.$this->source->id->value,
            'business_date' => $this->businessDate->toString(),
            'best_before_date' => $this->bestBeforeDate?->toString(),
            'collection_date' => $this->collectionDate?->toString(),
            'supplier_reference' => $this->supplierReference,
        ];
    }

    private static function assertDates(BusinessDate $received, ?BusinessDate $manufactured, ?BusinessDate $bestBefore, ?BusinessDate $collection = null): void
    {
        if ($collection !== null && $received->isBefore($collection)) {
            throw InvariantViolation::because('COLLECTION_DATE_INVALID', 'Collection date cannot be after the date DG Way received the goods.', ['field' => 'collection_date', 'rule' => 'not_after_receipt']);
        }
        if ($collection !== null && $manufactured !== null && $collection->isBefore($manufactured)) {
            throw InvariantViolation::because('COLLECTION_DATE_INVALID', 'Collection date cannot be before the manufactured date.', ['field' => 'collection_date', 'rule' => 'not_before_manufactured']);
        }
        if ($manufactured !== null && $received->isBefore($manufactured)) {
            throw InvariantViolation::because('LOT_DATES_INVALID', 'Manufactured date cannot be after the receipt date.', ['field' => 'manufactured_date']);
        }
        if ($manufactured !== null && $bestBefore !== null && $bestBefore->isBefore($manufactured)) {
            throw InvariantViolation::because('LOT_DATES_INVALID', 'Best-before date cannot be before the manufactured date.', ['field' => 'best_before_date']);
        }
    }
}
