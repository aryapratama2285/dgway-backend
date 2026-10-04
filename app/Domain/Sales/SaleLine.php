<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Exception\StateConflict;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Math\Decimal;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;

/**
 * One product on a sale with its TRANSACTION price snapshot. The unit price
 * belongs to this line forever (tomorrow's price change does not touch it).
 *
 *   subtotal = round(quantity × unit_price, 4)    (half-up, A1 Money policy)
 *   total    = subtotal − discount,  0 ≤ discount ≤ subtotal
 *
 * Returned quantity/amount grow through customer returns, never beyond what
 * was sold.
 */
final class SaleLine
{
    private function __construct(
        public readonly Id $id,
        public readonly int $lineNo,
        public readonly Id $productId,
        public readonly Quantity $quantity,
        public readonly Money $unitPrice,
        public readonly Money $discount,
        private Quantity $returnedQuantity,
        private Money $returnedAmount,
    ) {
    }

    public static function create(Id $id, int $lineNo, Id $productId, Quantity $quantity, Money $unitPrice, ?Money $discount = null): self
    {
        if ($lineNo < 1) {
            throw InvariantViolation::because('LINE_NO_INVALID', 'Line numbers start at 1.');
        }
        $quantity->assertPositive('Sale quantity');
        $unitPrice->assertNonNegative('Unit price');
        $discount ??= Money::zero($unitPrice->currency);
        $discount->assertNonNegative('Discount');
        $subtotal = $unitPrice->multipliedBy($quantity->value());
        if ($discount->isGreaterThan($subtotal)) {
            throw InvariantViolation::because('DISCOUNT_EXCEEDS_SUBTOTAL', "Line {$lineNo}: discount {$discount->toStorage()} is more than the line subtotal {$subtotal->toStorage()}.", ['line_no' => $lineNo]);
        }

        return new self($id, $lineNo, $productId, $quantity, $unitPrice, $discount, Quantity::zero($quantity->uom), Money::zero($unitPrice->currency));
    }

    public static function reconstitute(Id $id, int $lineNo, Id $productId, Quantity $quantity, Money $unitPrice, Money $discount, Quantity $returnedQuantity, Money $returnedAmount): self
    {
        return new self($id, $lineNo, $productId, $quantity, $unitPrice, $discount, $returnedQuantity, $returnedAmount);
    }

    public function subtotal(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity->value());
    }

    public function total(): Money
    {
        return $this->subtotal()->minus($this->discount);
    }

    public function returnedQuantity(): Quantity
    {
        return $this->returnedQuantity;
    }

    public function returnedAmount(): Money
    {
        return $this->returnedAmount;
    }

    public function returnableQuantity(): Quantity
    {
        return $this->quantity->minus($this->returnedQuantity);
    }

    /**
     * Value of taking back $qty at the net transaction price (discount
     * included). Pro-rata, rounded once to 4 dp; the return that empties the
     * line gets exactly the remainder, so Σ returned = line total.
     * Pure: validates and prices, changes nothing.
     */
    public function quoteReturn(Quantity $qty): Money
    {
        $qty->assertPositive('Return quantity');
        if (! $qty->uom->equals($this->quantity->uom)) {
            throw InvariantViolation::because('UOM_MISMATCH', "Line {$this->lineNo} was sold in {$this->quantity->uom->code}.");
        }
        $returnable = $this->returnableQuantity();
        if ($qty->isGreaterThan($returnable)) {
            throw StateConflict::because('RETURN_EXCEEDS_SOLD',
                "Line {$this->lineNo}: only {$returnable->toStorage()} {$returnable->uom->code} can still be returned (sold {$this->quantity->toStorage()}, returned {$this->returnedQuantity->toStorage()}).",
                ['sale_line_id' => $this->id->value, 'returnable' => $returnable->toStorage(), 'requested' => $qty->toStorage()]);
        }

        return $qty->equals($returnable)
            ? $this->total()->minus($this->returnedAmount)
            : Money::of($this->total()->amount()->multipliedBy($qty->value())->dividedBy($this->quantity->value(), Money::SCALE)->toFixed(Money::SCALE), $this->unitPrice->currency);
    }

    /** @internal use Sale::recordReturn() */
    public function takeBack(Quantity $qty): Money
    {
        $value = $this->quoteReturn($qty);
        $this->returnedQuantity = $this->returnedQuantity->plus($qty);
        $this->returnedAmount = $this->returnedAmount->plus($value);

        return $value;
    }
}
