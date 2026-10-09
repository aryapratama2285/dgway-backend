<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;

/**
 * One product on a purchase order. Entity inside the PurchaseOrder aggregate.
 *
 * lineTotal = orderedQuantity × unitCost, rounded half-up to 4 decimals
 * (Money precision). Whole-Rupiah rounding only happens on documents/prints.
 *
 * F4A: a line may be bought in a purchasing unit (e.g. TRAY). [$purchasedIn]
 * keeps the agreed terms in that unit (quantity, price, conversion snapshot);
 * orderedQuantity / receivedQuantity / unitCost stay in the BASE unit, and
 * lineTotal = purchasing quantity × purchasing price (exact agreed amount).
 */
final class PurchaseOrderLine
{
    private function __construct(
        public readonly Id $id,
        public readonly int $lineNo,
        public readonly Id $productId,
        public readonly Quantity $orderedQuantity,
        public readonly Money $unitCost,
        private Quantity $receivedQuantity,
        public readonly PurchasedIn $purchasedIn,
    ) {
    }

    public static function create(Id $id, int $lineNo, Id $productId, Quantity $ordered, Money $unitCost): self
    {
        $ordered->assertPositive('Ordered quantity');
        $unitCost->assertNonNegative('Unit cost');
        if ($lineNo < 1) {
            throw InvariantViolation::because('LINE_NO_INVALID', 'Line numbers start at 1.');
        }

        return new self($id, $lineNo, $productId, $ordered, $unitCost, Quantity::zero($ordered->uom), PurchasedIn::inBaseUnit($ordered, $unitCost));
    }

    /** F4A: a line agreed in a purchasing unit; the base quantity and base unit cost are derived from it. */
    public static function createPurchased(Id $id, int $lineNo, Id $productId, PurchasedIn $purchasedIn): self
    {
        if ($lineNo < 1) {
            throw InvariantViolation::because('LINE_NO_INVALID', 'Line numbers start at 1.');
        }
        $ordered = $purchasedIn->baseQuantity();

        return new self($id, $lineNo, $productId, $ordered, $purchasedIn->baseUnitCost(), Quantity::zero($ordered->uom), $purchasedIn);
    }

    public static function reconstitute(Id $id, int $lineNo, Id $productId, Quantity $ordered, Money $unitCost, Quantity $received, ?PurchasedIn $purchasedIn = null): self
    {
        return new self($id, $lineNo, $productId, $ordered, $unitCost, $received, $purchasedIn ?? PurchasedIn::inBaseUnit($ordered, $unitCost));
    }

    /** The agreed amount: purchasing quantity × purchasing price (= ordered × unit cost for a base-unit line). */
    public function lineTotal(): Money
    {
        return $this->purchasedIn->total();
    }

    /** CLOSED orders: what was ordered but will never be received (history, not a rewrite). */
    public function unfulfilledQuantity(): Quantity
    {
        return $this->openQuantity();
    }

    public function receivedQuantity(): Quantity
    {
        return $this->receivedQuantity;
    }

    public function openQuantity(): Quantity
    {
        return $this->orderedQuantity->minus($this->receivedQuantity);
    }

    public function isFullyReceived(): bool
    {
        return ! $this->openQuantity()->isPositive();
    }

    /** @internal via PurchaseOrder */
    public function receive(Quantity $quantity): void
    {
        $quantity->assertPositive('Received quantity');
        if ($quantity->isGreaterThan($this->openQuantity())) {
            throw InvariantViolation::because(
                'OVER_RECEIPT',
                "Receiving {$quantity->toStorage()} exceeds the open quantity {$this->openQuantity()->toStorage()} of PO line {$this->lineNo}. Over-receipt is not allowed.",
                ['line_no' => $this->lineNo, 'open' => $this->openQuantity()->toStorage(), 'requested' => $quantity->toStorage()],
            );
        }
        $this->receivedQuantity = $this->receivedQuantity->plus($quantity);
    }

    /** @internal via PurchaseOrder (receipt reversal) */
    public function unreceive(Quantity $quantity): void
    {
        $quantity->assertPositive('Reversed quantity');
        if ($quantity->isGreaterThan($this->receivedQuantity)) {
            throw InvariantViolation::because('UNRECEIVE_EXCEEDS_RECEIVED', "Cannot reverse more than was received on PO line {$this->lineNo}.");
        }
        $this->receivedQuantity = $this->receivedQuantity->minus($quantity);
    }
}
