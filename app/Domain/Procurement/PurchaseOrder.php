<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * A commitment to buy from a supplier, to be received at a node.
 *
 * References supplier, node and products by id only. Names are not copied:
 * master records are never deleted and their codes are immutable, so the
 * reference stays resolvable for the life of the document.
 */
final class PurchaseOrder extends AggregateRoot
{
    /** @var array<string, PurchaseOrderLine> keyed by line id */
    private array $lines = [];

    /** @param list<PurchaseOrderLine> $lines */
    private function __construct(
        Id $id,
        int $version,
        public readonly DocumentNumber $number,
        public readonly Id $supplierId,
        public readonly Id $nodeId,
        public readonly BusinessDate $businessDate,
        public readonly Currency $currency,
        array $lines,
        private PurchaseOrderStatus $status,
        private ?string $notes,
        private ?string $cancelReason,
        private ?PurchaseOrderLifecycle $lifecycle = null,
    ) {
        parent::__construct($id, $version);
        $this->lifecycle ??= new PurchaseOrderLifecycle();
        foreach ($lines as $line) {
            $this->lines[$line->id->value] = $line;
        }
    }

    /** @param list<PurchaseOrderLine> $lines */
    public static function draft(Id $id, DocumentNumber $number, Id $supplierId, Id $nodeId, BusinessDate $businessDate, array $lines, ?string $notes = null): self
    {
        $currency = self::assertLines($lines);

        return new self($id, 0, $number, $supplierId, $nodeId, $businessDate, $currency, $lines, PurchaseOrderStatus::DRAFT, Notes::clean($notes), null);
    }

    /**
     * F4A: the edited version of a DRAFT order (same id, number and version; the
     * repository replaces header and lines under optimistic locking). Every
     * draft rule is checked again. Anything but a DRAFT is refused (409).
     *
     * @param  list<PurchaseOrderLine>  $lines
     */
    public static function revisedDraft(self $draft, Id $supplierId, Id $nodeId, BusinessDate $businessDate, array $lines, ?string $notes): self
    {
        if ($draft->status !== PurchaseOrderStatus::DRAFT) {
            throw InvalidTransition::notEditable('Purchase order', $draft->status->value);
        }
        $currency = self::assertLines($lines);

        return new self($draft->id(), $draft->version(), $draft->number, $supplierId, $nodeId, $businessDate, $currency, $lines, PurchaseOrderStatus::DRAFT, Notes::clean($notes), null);
    }

    /** @param list<PurchaseOrderLine> $lines */
    private static function assertLines(array $lines): Currency
    {
        if ($lines === []) {
            throw InvariantViolation::because('PO_LINES_REQUIRED', 'A purchase order needs at least one line.');
        }
        $currency = $lines[0]->unitCost->currency;
        $seenProducts = [];
        $seenNos = [];
        foreach ($lines as $line) {
            if (! $line->unitCost->currency->equals($currency)) {
                throw InvariantViolation::because('CURRENCY_MISMATCH', 'All lines of a purchase order must use the same currency.');
            }
            if (isset($seenProducts[$line->productId->value])) {
                throw InvariantViolation::because('PO_DUPLICATE_PRODUCT', 'Each product may appear only once per purchase order.');
            }
            if (isset($seenNos[$line->lineNo])) {
                throw InvariantViolation::because('LINE_NO_DUPLICATE', 'Line numbers must be unique.');
            }
            $seenProducts[$line->productId->value] = true;
            $seenNos[$line->lineNo] = true;
        }

        return $currency;
    }

    /** @param list<PurchaseOrderLine> $lines */
    public static function reconstitute(Id $id, int $version, DocumentNumber $number, Id $supplierId, Id $nodeId, BusinessDate $businessDate, Currency $currency, array $lines, PurchaseOrderStatus $status, ?string $notes, ?string $cancelReason, ?PurchaseOrderLifecycle $lifecycle = null): self
    {
        return new self($id, $version, $number, $supplierId, $nodeId, $businessDate, $currency, $lines, $status, $notes, $cancelReason, $lifecycle);
    }

    public function confirm(?DateTimeImmutable $at = null, ?string $by = null): void
    {
        $this->transitionTo(PurchaseOrderStatus::CONFIRMED);
        $this->lifecycle->confirmedAt = $at ?? new DateTimeImmutable();
        $this->lifecycle->confirmedBy = $by;
    }

    /**
     * F4A: the procurement is finished although part of it may never arrive.
     * Ordered quantities are NOT rewritten: ordered − received stays visible as
     * the unfulfilled quantity. Only PARTIALLY_RECEIVED or RECEIVED orders close
     * (a CONFIRMED order with nothing received is cancelled instead).
     */
    public function close(PurchaseOrderCloseReason $reason, ?string $note, DateTimeImmutable $at, ?string $by = null): void
    {
        if (! in_array($this->status, [PurchaseOrderStatus::PARTIALLY_RECEIVED, PurchaseOrderStatus::RECEIVED], true)) {
            throw InvalidTransition::of('Purchase order', $this->status->value, PurchaseOrderStatus::CLOSED->value,
                'Only PARTIALLY_RECEIVED or RECEIVED orders can be closed; cancel an order that received nothing.');
        }
        $note = Notes::clean($note);
        $note = $note === null ? null : mb_substr($note, 0, 500);
        if ($reason->requiresNote() && $note === null) {
            throw InvariantViolation::because('CLOSE_REASON_REQUIRED', 'Closing for reason OTHER needs a note explaining why.', ['field' => 'note']);
        }
        $this->transitionTo(PurchaseOrderStatus::CLOSED);
        $this->lifecycle->closedAt = $at;
        $this->lifecycle->closedBy = $by;
        $this->lifecycle->closeReason = $reason;
        $this->lifecycle->closeNote = $note;
    }

    public function cancel(string $reason, ?DateTimeImmutable $at = null, ?string $by = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('CANCEL_REASON_REQUIRED', 'A cancellation reason is required.');
        }
        foreach ($this->lines as $line) {
            if ($line->receivedQuantity()->isPositive()) {
                throw InvariantViolation::because('PO_HAS_RECEIPTS', 'A purchase order with posted receipts cannot be cancelled. Reverse the receipts first.');
            }
        }
        $this->transitionTo(PurchaseOrderStatus::CANCELLED);
        $this->cancelReason = mb_substr($reason, 0, 500);
        $this->lifecycle->cancelledAt = $at ?? new DateTimeImmutable();
        $this->lifecycle->cancelledBy = $by;
    }

    /** Called when a goods receipt for this PO is posted. */
    public function recordReceipt(Id $lineId, Quantity $quantity): void
    {
        if (! $this->status->acceptsReceipts()) {
            throw InvalidTransition::of('Purchase order', $this->status->value, 'RECEIVED', 'Only CONFIRMED or PARTIALLY_RECEIVED orders accept receipts.');
        }
        $this->line($lineId)->receive($quantity);
        $this->recomputeReceiptStatus();
    }

    /** Called when a posted goods receipt for this PO is reversed. */
    public function reverseReceipt(Id $lineId, Quantity $quantity): void
    {
        if ($this->status === PurchaseOrderStatus::CANCELLED || $this->status === PurchaseOrderStatus::DRAFT) {
            throw InvalidTransition::of('Purchase order', $this->status->value, 'CONFIRMED', 'No receipts can exist on this order.');
        }
        if ($this->status === PurchaseOrderStatus::CLOSED) {
            throw InvalidTransition::of('Purchase order', $this->status->value, 'CONFIRMED',
                'The order is CLOSED (terminal, read-only); its receipts can no longer be reversed. Return goods through a separate transaction.');
        }
        $this->line($lineId)->unreceive($quantity);
        $this->recomputeReceiptStatus();
    }

    public function line(Id $lineId): PurchaseOrderLine
    {
        return $this->lines[$lineId->value] ?? throw InvariantViolation::because('PO_LINE_NOT_FOUND', 'Purchase order line not found on this order.', ['line_id' => $lineId->value]);
    }

    /** @return list<PurchaseOrderLine> ordered by line number */
    public function lines(): array
    {
        $lines = array_values($this->lines);
        usort($lines, fn (PurchaseOrderLine $a, PurchaseOrderLine $b) => $a->lineNo <=> $b->lineNo);

        return $lines;
    }

    public function totalAmount(): Money
    {
        return array_reduce($this->lines(), fn (Money $sum, PurchaseOrderLine $l) => $sum->plus($l->lineTotal()), Money::zero($this->currency));
    }

    public function status(): PurchaseOrderStatus
    {
        return $this->status;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function lifecycle(): PurchaseOrderLifecycle
    {
        return clone $this->lifecycle;
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'status' => $this->status->value,
            'supplier_id' => $this->supplierId->value,
            'node_id' => $this->nodeId->value,
            'total' => $this->totalAmount()->toStorage(),
            'lines' => array_map(fn (PurchaseOrderLine $l) => [
                'line_no' => $l->lineNo, 'product_id' => $l->productId->value,
                'ordered' => $l->orderedQuantity->toStorage(), 'received' => $l->receivedQuantity()->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'purchase_uom' => $l->purchasedIn->quantity->uom->code, 'purchase_quantity' => $l->purchasedIn->quantity->toStorage(),
                'purchase_unit_price' => $l->purchasedIn->unitPrice->toStorage(), 'conversion_factor' => $l->purchasedIn->conversion->factorToStorage(),
            ], $this->lines()),
            'cancel_reason' => $this->cancelReason,
            'close_reason_code' => $this->lifecycle->closeReason?->value,
            'close_note' => $this->lifecycle->closeNote,
        ];
    }

    private function recomputeReceiptStatus(): void
    {
        $anyReceived = false;
        $allReceived = true;
        foreach ($this->lines as $line) {
            $anyReceived = $anyReceived || $line->receivedQuantity()->isPositive();
            $allReceived = $allReceived && $line->isFullyReceived();
        }
        $next = match (true) {
            $allReceived => PurchaseOrderStatus::RECEIVED,
            $anyReceived => PurchaseOrderStatus::PARTIALLY_RECEIVED,
            default => PurchaseOrderStatus::CONFIRMED,
        };
        if ($next !== $this->status) {
            $this->transitionTo($next);
        }
    }

    private function transitionTo(PurchaseOrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidTransition::of('Purchase order', $this->status->value, $next->value);
        }
        $this->status = $next;
    }
}
