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
    ) {
    }

    public static function create(Id $id, int $lineNo, Id $productId, Quantity $ordered, Money $unitCost): self
    {
        $ordered->assertPositive('Ordered quantity');
        $unitCost->assertNonNegative('Unit cost');
        if ($lineNo < 1) {
            throw InvariantViolation::because('LINE_NO_INVALID', 'Line numbers start at 1.');
        }

        return new self($id, $lineNo, $productId, $ordered, $unitCost, Quantity::zero($ordered->uom));
    }

    public static function reconstitute(Id $id, int $lineNo, Id $productId, Quantity $ordered, Money $unitCost, Quantity $received): self
    {
        return new self($id, $lineNo, $productId, $ordered, $unitCost, $received);
    }

    public function lineTotal(): Money
    {
        return $this->unitCost->multipliedBy($this->orderedQuantity->value());
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
