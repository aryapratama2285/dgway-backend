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
 * Physical receipt of goods from a supplier at a node — with or without a
 * purchase order (direct purchase). Both paths produce the same document and
 * post to the same inventory ledger.
 */
final class GoodsReceipt extends AggregateRoot
{
    /** @param list<GoodsReceiptLine> $lines */
    private function __construct(
        Id $id,
        int $version,
        public readonly DocumentNumber $number,
        public readonly Id $supplierId,
        public readonly Id $nodeId,
        public readonly ?Id $purchaseOrderId,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $receivedAt,
        public readonly Currency $currency,
        private readonly array $lines,
        private GoodsReceiptStatus $status,
        private ?string $notes,
        private ?DateTimeImmutable $postedAt,
        private ?DateTimeImmutable $reversedAt,
        private ?string $statusReason,
    ) {
        parent::__construct($id, $version);
    }

    /**
     * @param  list<GoodsReceiptLine>  $lines
     * @param  ?PurchaseOrder  $purchaseOrder  null for a direct purchase
     */
    public static function draft(
        Id $id,
        DocumentNumber $number,
        Id $supplierId,
        Id $nodeId,
        BusinessDate $businessDate,
        DateTimeImmutable $receivedAt,
        array $lines,
        ?PurchaseOrder $purchaseOrder = null,
        ?string $notes = null,
    ): self {
        $currency = self::assertLines($businessDate, $lines, $purchaseOrder);
        self::matchPurchaseOrder($purchaseOrder, $supplierId, $nodeId, $lines, $currency);

        return new self($id, 0, $number, $supplierId, $nodeId, $purchaseOrder?->id(), $businessDate, $receivedAt, $currency, array_values($lines), GoodsReceiptStatus::DRAFT, Notes::clean($notes), null, null, null);
    }

    /**
     * F4A: the edited version of a DRAFT receipt (same id, number, supplier,
     * node and purchase order; date, received_at, notes and lines may change).
     * Every draft rule is checked again; anything but a DRAFT is refused (409).
     *
     * @param  list<GoodsReceiptLine>  $lines
     */
    public static function revisedDraft(self $draft, BusinessDate $businessDate, DateTimeImmutable $receivedAt, array $lines, ?PurchaseOrder $purchaseOrder, ?string $notes): self
    {
        if ($draft->status !== GoodsReceiptStatus::DRAFT) {
            throw InvalidTransition::notEditable('Goods receipt', $draft->status->value);
        }
        $currency = self::assertLines($businessDate, $lines, $purchaseOrder);
        self::matchPurchaseOrder($purchaseOrder, $draft->supplierId, $draft->nodeId, $lines, $currency);

        return new self($draft->id(), $draft->version(), $draft->number, $draft->supplierId, $draft->nodeId, $draft->purchaseOrderId, $businessDate, $receivedAt, $currency,
            array_values($lines), GoodsReceiptStatus::DRAFT, Notes::clean($notes), null, null, null);
    }

    /** @param list<GoodsReceiptLine> $lines */
    private static function matchPurchaseOrder(?PurchaseOrder $purchaseOrder, Id $supplierId, Id $nodeId, array $lines, Currency $currency): void
    {
        if ($purchaseOrder !== null) {
            self::assertMatchesPurchaseOrder($purchaseOrder, $supplierId, $nodeId, $lines, $currency);
        } else {
            foreach ($lines as $line) {
                if ($line->purchaseOrderLineId !== null) {
                    throw InvariantViolation::because('GR_PO_LINE_WITHOUT_PO', 'A direct receipt cannot reference purchase order lines.');
                }
            }
        }
    }

    /** @param list<GoodsReceiptLine> $lines */
    private static function assertLines(BusinessDate $businessDate, array $lines, ?PurchaseOrder $purchaseOrder): Currency
    {
        if ($lines === []) {
            throw InvariantViolation::because('GR_LINES_REQUIRED', 'A goods receipt needs at least one line.');
        }
        $currency = $lines[0]->unitCost->currency;
        $seenNos = [];
        $seenProducts = [];
        foreach ($lines as $line) {
            if (! $line->unitCost->currency->equals($currency)) {
                throw InvariantViolation::because('CURRENCY_MISMATCH', 'All lines of a receipt must use the same currency.');
            }
            if (isset($seenNos[$line->lineNo])) {
                throw InvariantViolation::because('LINE_NO_DUPLICATE', 'Line numbers must be unique.');
            }
            if (isset($seenProducts[$line->productId->value])) {
                throw InvariantViolation::because('GR_DUPLICATE_PRODUCT', 'Each product may appear only once per receipt.');
            }
            $seenNos[$line->lineNo] = true;
            $seenProducts[$line->productId->value] = true;
            // goods cannot have been collected upstream after DG Way received them (A5-C)
            if ($line->collectionDate !== null && $businessDate->isBefore($line->collectionDate)) {
                throw InvariantViolation::because('COLLECTION_DATE_INVALID',
                    "Line {$line->lineNo}: collection date {$line->collectionDate->toString()} is after the receipt date {$businessDate->toString()}.",
                    ['field' => 'lines.'.($line->lineNo - 1).'.collection_date', 'rule' => 'not_after_receipt']);
            }
        }

        return $currency;
    }

    /** @param list<GoodsReceiptLine> $lines */
    public static function reconstitute(
        Id $id, int $version, DocumentNumber $number, Id $supplierId, Id $nodeId, ?Id $purchaseOrderId, BusinessDate $businessDate,
        DateTimeImmutable $receivedAt, Currency $currency, array $lines, GoodsReceiptStatus $status, ?string $notes,
        ?DateTimeImmutable $postedAt, ?DateTimeImmutable $reversedAt, ?string $statusReason,
    ): self {
        return new self($id, $version, $number, $supplierId, $nodeId, $purchaseOrderId, $businessDate, $receivedAt, $currency, $lines, $status, $notes, $postedAt, $reversedAt, $statusReason);
    }

    /**
     * Mark as posted. The caller (application) writes the stock movements in
     * the same transaction; a second post of the same receipt is rejected
     * here, which is what prevents double stock.
     */
    public function post(DateTimeImmutable $at): void
    {
        $this->transitionTo(GoodsReceiptStatus::POSTED);
        $this->postedAt = $at;
    }

    public function reverse(DateTimeImmutable $at, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('REVERSAL_REASON_REQUIRED', 'A reversal reason is required.');
        }
        $this->transitionTo(GoodsReceiptStatus::REVERSED);
        $this->reversedAt = $at;
        $this->statusReason = mb_substr($reason, 0, 500);
    }

    public function cancel(string $reason): void
    {
        $this->transitionTo(GoodsReceiptStatus::CANCELLED);
        $this->statusReason = mb_substr(trim($reason) ?: 'cancelled', 0, 500);
    }

    /** @return list<GoodsReceiptLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalCost(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, GoodsReceiptLine $l) => $sum->plus($l->lineCost()), Money::zero($this->currency));
    }

    /**
     * Raw yield figures per line for analysis (effective HPP, supplier
     * quality). `cost_per_accepted_unit` is ONE analytical view (all purchase
     * cost carried by accepted units) — not a booked accounting policy.
     *
     * @return list<array<string, string|int|null>>
     */
    public function yieldSummary(): array
    {
        return array_map(function (GoodsReceiptLine $l): array {
            $accepted = $l->quantityOf(DispositionType::ACCEPT);

            return [
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom' => $l->receivedQuantity->uom->code,
                'received' => $l->receivedQuantity->toStorage(),
                'accepted' => $accepted->toStorage(),
                'quarantined' => $l->quantityOf(DispositionType::QUARANTINE)->toStorage(),
                'loss' => $l->quantityOf(DispositionType::LOSS)->toStorage(),
                'returned_to_supplier' => $l->quantityOf(DispositionType::RETURN_TO_SUPPLIER)->toStorage(),
                'regraded' => $l->quantityOf(DispositionType::REGRADE)->toStorage(),
                'purchase_cost' => $l->lineCost()->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'cost_per_accepted_unit' => $accepted->isPositive() ? $l->lineCost()->dividedBy($accepted->value())->toStorage() : null,
            ];
        }, $this->lines);
    }

    public function status(): GoodsReceiptStatus
    {
        return $this->status;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function postedAt(): ?DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function reversedAt(): ?DateTimeImmutable
    {
        return $this->reversedAt;
    }

    public function statusReason(): ?string
    {
        return $this->statusReason;
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'status' => $this->status->value,
            'supplier_id' => $this->supplierId->value,
            'node_id' => $this->nodeId->value,
            'purchase_order_id' => $this->purchaseOrderId?->value,
            'business_date' => $this->businessDate->toString(),
            'total_cost' => $this->totalCost()->toStorage(),
            'lines' => $this->yieldSummary(),
            'status_reason' => $this->statusReason,
        ];
    }

    /** @param list<GoodsReceiptLine> $lines */
    private static function assertMatchesPurchaseOrder(PurchaseOrder $po, Id $supplierId, Id $nodeId, array $lines, Currency $currency): void
    {
        if (! $po->status()->acceptsReceipts()) {
            throw InvariantViolation::because('PO_NOT_RECEIVABLE', "Purchase order {$po->number} is {$po->status()->value}; only CONFIRMED or PARTIALLY_RECEIVED orders can be received.");
        }
        // one code for every mismatch; details.field says which (F4A, additive)
        if (! $po->supplierId->equals($supplierId)) {
            throw InvariantViolation::because('GR_PO_MISMATCH', 'Receipt supplier must match the purchase order.', ['field' => 'supplier_id']);
        }
        if (! $po->nodeId->equals($nodeId)) {
            throw InvariantViolation::because('GR_PO_MISMATCH', 'Receipt node (destination) must match the purchase order.', ['field' => 'node_id']);
        }
        if (! $po->currency->equals($currency)) {
            throw InvariantViolation::because('CURRENCY_MISMATCH', "Receipt currency {$currency->code} differs from the purchase order currency {$po->currency->code}.", ['field' => 'lines']);
        }
        foreach ($lines as $line) {
            $i = $line->lineNo - 1;
            if ($line->purchaseOrderLineId === null) {
                throw InvariantViolation::because('GR_PO_LINE_REQUIRED', "Line {$line->lineNo}: a receipt against a purchase order must reference its PO line.", ['field' => "lines.{$i}.purchase_order_line_id"]);
            }
            $poLine = $po->line($line->purchaseOrderLineId);
            if (! $poLine->productId->equals($line->productId)) {
                throw InvariantViolation::because('GR_PO_MISMATCH', "Line {$line->lineNo}: product differs from the purchase order line.", ['field' => "lines.{$i}.product_id"]);
            }
            if ($line->receivedQuantity->isGreaterThan($poLine->openQuantity())) {
                throw InvariantViolation::because('OVER_RECEIPT', "Line {$line->lineNo}: received {$line->receivedQuantity->toStorage()} exceeds open quantity {$poLine->openQuantity()->toStorage()}. Over-receipt is not allowed.",
                    ['field' => "lines.{$i}.quantity", 'line_no' => $line->lineNo, 'open' => $poLine->openQuantity()->toStorage(), 'requested' => $line->receivedQuantity->toStorage(), 'uom' => $poLine->orderedQuantity->uom->code]);
            }
        }
    }

    private function transitionTo(GoodsReceiptStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidTransition::of('Goods receipt', $this->status->value, $next->value);
        }
        $this->status = $next;
    }
}
