<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;

/**
 * One received product on a goods receipt, with the decision for every part
 * of it.
 *
 * MASS BALANCE: received = Σ dispositions (ACCEPT + LOSS + RETURN + REGRADE …).
 * Checked on construction, so a line that "loses" quantity cannot exist.
 *
 * Cost: the purchase cost is for the whole received quantity
 * (lineCost = received × unitCost), whatever happens to it afterwards.
 *
 * F4A: [$purchasedIn] keeps the line as received in its purchasing unit (quantity,
 * ACTUAL unit cost per that unit, conversion snapshot). receivedQuantity and
 * unitCost (per base unit) are derived from it; lineCost = purchasing quantity ×
 * purchasing unit cost (exact). Dispositions are always in the base unit.
 */
final class GoodsReceiptLine
{
    /** @param list<Disposition> $dispositions */
    private function __construct(
        public readonly Id $id,
        public readonly int $lineNo,
        public readonly Id $productId,
        public readonly Quantity $receivedQuantity,
        public readonly Money $unitCost,
        public readonly ?Id $purchaseOrderLineId,
        public readonly array $dispositions,
        public readonly ?BusinessDate $manufacturedDate = null,
        public readonly ?BusinessDate $bestBeforeDate = null,
        public readonly ?BusinessDate $collectionDate = null,
        public readonly ?string $supplierReference = null,
        ?PurchasedIn $purchasedIn = null,
    ) {
        $this->purchasedIn = $purchasedIn ?? PurchasedIn::inBaseUnit($receivedQuantity, $unitCost);
    }

    public readonly PurchasedIn $purchasedIn;

    public const SUPPLIER_REFERENCE_MAX = 60;

    /** @param list<Disposition> $dispositions */
    /**
     * A5-C upstream facts, all optional and never inferred:
     *   collectionDate     date the supplier/farm states the goods were collected (eggs: collected/separated);
     *                      must not be after the receipt date (checked by GoodsReceipt::draft) nor before a
     *                      stated manufactured date. UNKNOWN stays null — it is never copied from the receipt date.
     *   supplierReference  the supplier's own batch/reference text, only if the supplier really gave one.
     */
    public static function create(Id $id, int $lineNo, Id $productId, Quantity $received, Money $unitCost, array $dispositions, ?Id $purchaseOrderLineId = null,
        ?BusinessDate $manufacturedDate = null, ?BusinessDate $bestBeforeDate = null, ?BusinessDate $collectionDate = null, ?string $supplierReference = null,
        ?PurchasedIn $purchasedIn = null): self
    {
        if ($purchasedIn !== null && (! $purchasedIn->baseQuantity()->equals($received) || ! $purchasedIn->baseUnitCost()->equals($unitCost))) {
            throw InvariantViolation::because('UOM_CONVERSION_INVALID', "Line {$lineNo}: the base quantity/cost do not match the purchasing-unit terms.");
        }
        if ($collectionDate !== null && $manufacturedDate !== null && $collectionDate->isBefore($manufacturedDate)) {
            throw InvariantViolation::because('COLLECTION_DATE_INVALID', "Line {$lineNo}: the collection date cannot be before the manufactured date.", ['field' => 'collection_date', 'rule' => 'not_before_manufactured']);
        }
        $supplierReference = self::cleanReference($lineNo, $supplierReference);
        if ($manufacturedDate !== null && $bestBeforeDate !== null && $bestBeforeDate->isBefore($manufacturedDate)) {
            throw InvariantViolation::because('LOT_DATES_INVALID', "Line {$lineNo}: best-before date cannot be before the manufactured date.", ['field' => 'best_before_date']);
        }
        if ($lineNo < 1) {
            throw InvariantViolation::because('LINE_NO_INVALID', 'Line numbers start at 1.');
        }
        $received->assertPositive('Received quantity');
        $unitCost->assertNonNegative('Unit cost');
        self::assertMassBalance($lineNo, $productId, $received, $dispositions);

        return new self($id, $lineNo, $productId, $received, $unitCost, $purchaseOrderLineId, array_values($dispositions), $manufacturedDate, $bestBeforeDate,
            $collectionDate, $supplierReference, $purchasedIn);
    }

    private static function cleanReference(int $lineNo, ?string $ref): ?string
    {
        $ref = $ref === null ? '' : trim(preg_replace('/\s+/u', ' ', $ref) ?? '');
        if ($ref === '') {
            return null;
        }
        if (mb_strlen($ref) > self::SUPPLIER_REFERENCE_MAX) {
            throw InvariantViolation::because('SUPPLIER_REFERENCE_TOO_LONG', "Line {$lineNo}: the supplier reference is longer than ".self::SUPPLIER_REFERENCE_MAX.' characters.', ['field' => 'supplier_reference']);
        }

        return $ref;
    }

    /** @param list<Disposition> $dispositions */
    public static function reconstitute(Id $id, int $lineNo, Id $productId, Quantity $received, Money $unitCost, array $dispositions, ?Id $purchaseOrderLineId,
        ?BusinessDate $manufacturedDate = null, ?BusinessDate $bestBeforeDate = null, ?BusinessDate $collectionDate = null, ?string $supplierReference = null,
        ?PurchasedIn $purchasedIn = null): self
    {
        return new self($id, $lineNo, $productId, $received, $unitCost, $purchaseOrderLineId, $dispositions, $manufacturedDate, $bestBeforeDate, $collectionDate, $supplierReference, $purchasedIn);
    }

    /** Actual cost of the whole received quantity: purchasing quantity × actual purchasing unit cost. */
    public function lineCost(): Money
    {
        return $this->purchasedIn->total();
    }

    public function quantityOf(DispositionType $type): Quantity
    {
        $sum = Quantity::zero($this->receivedQuantity->uom);
        foreach ($this->dispositions as $d) {
            if ($d->type === $type) {
                $sum = $sum->plus($d->quantity);
            }
        }

        return $sum;
    }

    /** @param list<Disposition> $dispositions */
    private static function assertMassBalance(int $lineNo, Id $productId, Quantity $received, array $dispositions): void
    {
        if ($dispositions === []) {
            throw InvariantViolation::because('DISPOSITION_REQUIRED', "Line {$lineNo}: every received quantity needs a disposition (e.g. ACCEPT).");
        }

        $total = Quantity::zero($received->uom);
        foreach ($dispositions as $d) {
            if (! $d->quantity->uom->equals($received->uom)) {
                throw InvariantViolation::because('UOM_MISMATCH', "Line {$lineNo}: dispositions must use the received unit {$received->uom->code}.");
            }
            if ($d->targetProductId !== null && $d->targetProductId->equals($productId)) {
                throw InvariantViolation::because('REGRADE_TO_SAME_PRODUCT', "Line {$lineNo}: a regrade must change the product.");
            }
            $total = $total->plus($d->quantity);
        }

        if (! $total->equals($received)) {
            throw InvariantViolation::because(
                'MASS_BALANCE_VIOLATED',
                "Line {$lineNo}: received {$received->toStorage()} but dispositions total {$total->toStorage()}. Every unit must be accounted for.",
                ['line_no' => $lineNo, 'received' => $received->toStorage(), 'dispositions_total' => $total->toStorage(), 'uom' => $received->uom->code],
            );
        }
    }
}
