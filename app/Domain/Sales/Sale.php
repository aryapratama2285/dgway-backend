<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Exception\StateConflict;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use LogicException;

/**
 * A sale: the business transaction "these goods left DG Way for this price".
 *
 * Consistency boundary for the money of ONE sale. Everything that changes
 * what is owed on it goes through this aggregate (and therefore through a
 * row lock on the sale):
 *
 *   outstanding = grand_total − Σ valid payments − Σ return credits   (never < 0)
 *
 * Payments and customer returns are separate aggregates with their own,
 * append-only history; the sale only keeps the running sums (paid, credited)
 * which the database re-checks against that history at commit.
 *
 * Stock is not modelled here: the application posts SALE movements to the A3
 * ledger in the same transaction as confirm().
 */
final class Sale extends AggregateRoot
{
    /** @param list<SaleLine> $lines */
    private function __construct(
        Id $id,
        int $version,
        public readonly DocumentNumber $number,
        public readonly SaleOrigin $origin,
        public readonly ?Id $orderReferenceId,
        public readonly ?Id $customerId,
        public readonly Id $nodeId,
        public readonly BusinessDate $businessDate,
        public readonly Currency $currency,
        private readonly array $lines,
        private SaleStatus $status,
        private Money $paid,
        private Money $credited,
        private ?int $termDays,
        private ?BusinessDate $dueDate,
        private ?DateTimeImmutable $confirmedAt,
        private ?DateTimeImmutable $completedAt,
        private ?DateTimeImmutable $reversedAt,
        private ?string $statusReason,
        private ?string $notes,
    ) {
        parent::__construct($id, $version);
    }

    /**
     * A direct sale (no customer order). $customerId null = anonymous walk-in.
     *
     * @param  list<SaleLine>  $lines
     */
    public static function direct(Id $id, DocumentNumber $number, ?Id $customerId, Id $nodeId, BusinessDate $date, array $lines, ?string $notes = null): self
    {
        if ($lines === []) {
            throw InvariantViolation::because('SALE_LINES_REQUIRED', 'A sale needs at least one line.');
        }
        $currency = $lines[0]->unitPrice->currency;
        $seen = [];
        foreach ($lines as $line) {
            if (! $line->unitPrice->currency->equals($currency)) {
                throw InvariantViolation::because('CURRENCY_MISMATCH', 'All lines of a sale must use the same currency.');
            }
            if (isset($seen[$line->lineNo])) {
                throw InvariantViolation::because('LINE_NO_DUPLICATE', 'Line numbers must be unique.');
            }
            $seen[$line->lineNo] = true;
        }

        return new self($id, 0, $number, SaleOrigin::DIRECT, null, $customerId, $nodeId, $date, $currency, array_values($lines), SaleStatus::DRAFT,
            Money::zero($currency), Money::zero($currency), null, null, null, null, null, null, Notes::clean($notes));
    }

    /** @param list<SaleLine> $lines */
    public static function reconstitute(
        Id $id, int $version, DocumentNumber $number, SaleOrigin $origin, ?Id $orderReferenceId, ?Id $customerId, Id $nodeId, BusinessDate $date,
        Currency $currency, array $lines, SaleStatus $status, Money $paid, Money $credited, ?int $termDays, ?BusinessDate $dueDate,
        ?DateTimeImmutable $confirmedAt, ?DateTimeImmutable $completedAt, ?DateTimeImmutable $reversedAt, ?string $statusReason, ?string $notes,
    ): self {
        return new self($id, $version, $number, $origin, $orderReferenceId, $customerId, $nodeId, $date, $currency, $lines, $status, $paid, $credited,
            $termDays, $dueDate, $confirmedAt, $completedAt, $reversedAt, $statusReason, $notes);
    }

    // ── commands ──────────────────────────────────────────────────────────

    /**
     * Makes the sale final. Takes the payments made at the counter, then
     * decides whether the rest may be owed:
     *   - walk-in or cash customer: nothing may remain (CREDIT_NOT_ALLOWED);
     *   - credit customer: existing exposure + what remains ≤ credit limit
     *     (CREDIT_LIMIT_EXCEEDED). The caller must hold the customer's credit
     *     lock while reading $existingExposure (see ConfirmSale).
     *
     * @param  list<PaymentRequest>  $payments
     * @return list<Payment>
     */
    public function confirm(DateTimeImmutable $at, CustomerAccount $account, Money $existingExposure, array $payments = [], ?CreditLimitOverride $override = null): array
    {
        if (($account->customerId?->value) !== ($this->customerId?->value)) {
            throw new LogicException('Customer account does not belong to this sale.');
        }
        // Only a DRAFT is confirmed (COMPLETED → CONFIRMED exists, but only for payment reversals).
        if ($this->status !== SaleStatus::DRAFT) {
            throw self::invalidTransition($this->status, SaleStatus::CONFIRMED);
        }

        // 1. decide (no state change yet): counter payments, then what remains owed
        $paidNow = Money::zero($this->currency);
        foreach ($payments as $p) {
            if (! $p->amount->isPositive()) {
                throw InvariantViolation::because('PAYMENT_AMOUNT_INVALID', 'A payment amount must be positive.', ['field' => 'amount']);
            }
            $paidNow = $paidNow->plus($p->amount);
        }
        $remaining = $this->grandTotal()->minus($paidNow);
        if ($remaining->isNegative()) {
            throw StateConflict::because('PAYMENT_EXCEEDS_OUTSTANDING',
                "Payments {$paidNow->toStorage()} are more than the sale total {$this->grandTotal()->toStorage()}. Deposits/prepayments are not supported.",
                ['outstanding' => $this->grandTotal()->toStorage(), 'amount' => $paidNow->toStorage()]);
        }
        if ($remaining->isPositive()) {
            if (! $account->mayOwe()) {
                throw InvariantViolation::because('CREDIT_NOT_ALLOWED',
                    ($account->isWalkIn() ? 'A walk-in sale' : 'A cash customer').' must be paid in full: '.$remaining->toStorage().' is still unpaid.',
                    ['outstanding' => $remaining->toStorage()]);
            }
            if ($account->creditLimit !== null) {
                $exposure = $existingExposure->plus($remaining);
                if ($exposure->isGreaterThan($account->creditLimit) && $override === null) {
                    $available = $account->creditLimit->minus($existingExposure);
                    throw StateConflict::because('CREDIT_LIMIT_EXCEEDED',
                        "Credit limit {$account->creditLimit->toStorage()} exceeded: already owed {$existingExposure->toStorage()}, this sale adds {$remaining->toStorage()}.",
                        [
                            'credit_limit' => $account->creditLimit->toStorage(),
                            'existing_outstanding' => $existingExposure->toStorage(),
                            'requested_credit' => $remaining->toStorage(),
                            'available_credit' => ($available->isNegative() ? Money::zero($this->currency) : $available)->toStorage(),
                        ]);
                }
            }
        }

        if ($override !== null) {
            $needed = $remaining->isPositive() && $account->mayOwe() && $account->creditLimit !== null
                && $existingExposure->plus($remaining)->isGreaterThan($account->creditLimit);
            if (! $needed) {
                throw StateConflict::because('CREDIT_OVERRIDE_NOT_REQUIRED',
                    'This sale does not exceed the credit limit; confirm it normally (an override is recorded only when it is actually used).',
                    ['credit_limit' => $account->creditLimit?->toStorage(), 'existing_outstanding' => $existingExposure->toStorage(), 'requested_credit' => $remaining->toStorage()]);
            }
        }

        // 2. apply
        $this->transitionTo(SaleStatus::CONFIRMED);
        $this->confirmedAt = $at;
        $this->termDays = $account->termDays;
        $this->dueDate = $this->businessDate->addDays($account->termDays);
        $recorded = array_map(fn (PaymentRequest $p) => $this->recordPayment($p), $payments);
        $this->settleIfPaid($at); // e.g. a fully discounted sale

        return $recorded;
    }

    public function recordPayment(PaymentRequest $request): Payment
    {
        if ($this->status !== SaleStatus::CONFIRMED) {
            throw StateConflict::because('SALE_NOT_PAYABLE', "Sale {$this->number->toString()} is {$this->status->value}; payments are accepted only while something is owed.",
                ['status' => $this->status->value]);
        }
        if (! $request->amount->isPositive()) {
            throw InvariantViolation::because('PAYMENT_AMOUNT_INVALID', 'A payment amount must be positive.', ['field' => 'amount']);
        }
        if (! $request->amount->currency->equals($this->currency)) {
            throw InvariantViolation::because('CURRENCY_MISMATCH', 'Payment currency differs from the sale currency.');
        }
        $outstanding = $this->outstanding();
        if ($request->amount->isGreaterThan($outstanding)) {
            throw StateConflict::because('PAYMENT_EXCEEDS_OUTSTANDING',
                "Payment {$request->amount->toStorage()} is more than the outstanding {$outstanding->toStorage()} of sale {$this->number->toString()}. Deposits/prepayments are not supported.",
                ['outstanding' => $outstanding->toStorage(), 'amount' => $request->amount->toStorage()]);
        }

        $this->paid = $this->paid->plus($request->amount);
        $this->settleIfPaid($request->paidAt);

        return Payment::fromRequest($request, $this->id(), $this->customerId);
    }

    /**
     * Cancels a payment that was recorded by mistake. The amount becomes
     * owed again (the sale goes back to CONFIRMED if it was COMPLETED).
     * A walk-in sale must always be fully paid, so its payments can only be
     * reversed together with the sale ({@see reverse()}).
     */
    public function reversePayment(Payment $payment, PaymentReversal $reversal): void
    {
        $this->doReversePayment($payment, $reversal, false);
    }

    /**
     * Records goods brought back against this sale's lines.
     *
     * @param  list<ReturnLineRequest>  $requests
     */
    public function recordReturn(Id $returnId, DocumentNumber $number, array $requests, ?PaymentMethod $refundMethod, string $reason,
        BusinessDate $date, DateTimeImmutable $at, ?string $actorUserId): CustomerReturn
    {
        if (! $this->status->isFinal()) {
            throw StateConflict::because('SALE_NOT_RETURNABLE', "Sale {$this->number->toString()} is {$this->status->value}; only confirmed sales accept returns.",
                ['status' => $this->status->value]);
        }
        if ($requests === []) {
            throw InvariantViolation::because('RETURN_LINES_REQUIRED', 'A return needs at least one line.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('REASON_REQUIRED', 'A return reason is required.', ['field' => 'reason']);
        }

        // 1. validate and price everything first (no state change yet)
        $total = Money::zero($this->currency);
        $quotes = [];
        foreach ($requests as $r) {
            if (isset($quotes[$r->saleLineId->value])) {
                throw InvariantViolation::because('RETURN_LINE_DUPLICATE', 'Each sale line may appear only once per return.', ['sale_line_id' => $r->saleLineId->value]);
            }
            $value = $this->line($r->saleLineId)->quoteReturn($r->quantity);
            $quotes[$r->saleLineId->value] = $value;
            $total = $total->plus($value);
        }

        $outstanding = $this->outstanding();
        $credit = $total->isGreaterThan($outstanding) ? $outstanding : $total;
        $refund = $total->minus($credit);
        if ($refund->isPositive() && $refundMethod === null) {
            throw InvariantViolation::because('REFUND_METHOD_REQUIRED',
                "This return is worth {$total->toStorage()} but only {$outstanding->toStorage()} is still owed; {$refund->toStorage()} must be refunded — say how (refund_method).",
                ['field' => 'refund_method', 'refund_amount' => $refund->toStorage()]);
        }

        // 2. apply
        $lines = [];
        foreach ($requests as $r) {
            $line = $this->line($r->saleLineId);
            $value = $line->takeBack($r->quantity);
            $lines[] = new CustomerReturnLine($r->id, $line->id, $line->productId, $r->quantity, $value, $r->disposition,
                $r->reason === null ? null : (mb_substr(trim($r->reason), 0, 500) ?: null));
        }

        $this->credited = $this->credited->plus($credit);
        $this->settleIfPaid($at);

        return CustomerReturn::create($returnId, $number, $this->id(), $this->customerId, $this->nodeId, $date, $at, $this->currency, $lines,
            $credit, $refund, $refund->isPositive() ? $refundMethod : null, mb_substr($reason, 0, 500), $actorUserId);
    }

    /**
     * Compensates a final sale completely (wrong customer, wrong price, …).
     * Policy (enforced):
     *   - a sale with customer returns cannot be reversed (return the rest instead);
     *   - payments are never deleted silently: if any valid payment exists the
     *     caller must ask for them to be reversed too ($reversePayments), in
     *     the same transaction — otherwise SALE_HAS_PAYMENTS.
     * The application posts REVERSAL movements for the sale's stock.
     *
     * @param  list<Payment>  $validPayments  every non-reversed payment of this sale
     * @param  callable(Payment): PaymentReversal  $reversalFor
     */
    public function reverse(DateTimeImmutable $at, string $reason, array $validPayments, bool $reversePayments, callable $reversalFor): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('REVERSAL_REASON_REQUIRED', 'A reversal reason is required.', ['field' => 'reason']);
        }
        if (! $this->status->isFinal()) {
            throw self::invalidTransition($this->status, SaleStatus::REVERSED);
        }
        foreach ($this->lines as $line) {
            if ($line->returnedQuantity()->isPositive()) {
                throw StateConflict::because('SALE_HAS_RETURNS', "Sale {$this->number->toString()} already has customer returns; record a return for the rest instead of reversing.");
            }
        }
        $sum = array_reduce($validPayments, fn (Money $s, Payment $p) => $s->plus($p->amount), Money::zero($this->currency));
        if (! $sum->equals($this->paid)) {
            throw new LogicException('The payments given are not all valid payments of this sale.');
        }
        if ($this->paid->isPositive() && ! $reversePayments) {
            throw StateConflict::because('SALE_HAS_PAYMENTS',
                "Sale {$this->number->toString()} has payments of {$this->paid->toStorage()}. Reverse them first, or reverse the sale with reverse_payments=true (refund).",
                ['paid' => $this->paid->toStorage()]);
        }
        foreach ($validPayments as $payment) {
            $this->doReversePayment($payment, $reversalFor($payment), true);
        }

        $this->transitionTo(SaleStatus::REVERSED);
        $this->reversedAt = $at;
        $this->completedAt = null;
        $this->statusReason = mb_substr($reason, 0, 500);
    }

    public function cancel(string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw InvariantViolation::because('CANCEL_REASON_REQUIRED', 'A cancellation reason is required.', ['field' => 'reason']);
        }
        $this->transitionTo(SaleStatus::CANCELLED);
        $this->statusReason = mb_substr($reason, 0, 500);
    }

    // ── queries ───────────────────────────────────────────────────────────

    /** @return list<SaleLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function line(Id $lineId): SaleLine
    {
        foreach ($this->lines as $line) {
            if ($line->id->equals($lineId)) {
                return $line;
            }
        }
        throw InvariantViolation::because('SALE_LINE_NOT_FOUND', "Line {$lineId->value} does not belong to sale {$this->number->toString()}.", ['sale_line_id' => $lineId->value]);
    }

    public function subtotal(): Money
    {
        return array_reduce($this->lines, fn (Money $s, SaleLine $l) => $s->plus($l->subtotal()), Money::zero($this->currency));
    }

    public function discountTotal(): Money
    {
        return array_reduce($this->lines, fn (Money $s, SaleLine $l) => $s->plus($l->discount), Money::zero($this->currency));
    }

    public function grandTotal(): Money
    {
        return $this->subtotal()->minus($this->discountTotal());
    }

    public function paid(): Money
    {
        return $this->paid;
    }

    public function credited(): Money
    {
        return $this->credited;
    }

    /** What is still owed. 0 unless the sale is final. */
    public function outstanding(): Money
    {
        return $this->status->isFinal() ? $this->grandTotal()->minus($this->paid)->minus($this->credited) : Money::zero($this->currency);
    }

    public function paymentStatus(): string
    {
        if (! $this->status->isFinal()) {
            return 'NONE';
        }

        return match (true) {
            $this->outstanding()->isZero() => 'PAID',
            $this->paid->isPositive() || $this->credited->isPositive() => 'PARTIALLY_PAID',
            default => 'UNPAID',
        };
    }

    public function isOverdue(BusinessDate $today): bool
    {
        return $this->outstanding()->isPositive() && $this->dueDate !== null && $this->dueDate->isBefore($today);
    }

    public function status(): SaleStatus
    {
        return $this->status;
    }

    public function termDays(): ?int
    {
        return $this->termDays;
    }

    public function dueDate(): ?BusinessDate
    {
        return $this->dueDate;
    }

    public function confirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function reversedAt(): ?DateTimeImmutable
    {
        return $this->reversedAt;
    }

    public function statusReason(): ?string
    {
        return $this->statusReason;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'status' => $this->status->value,
            'customer_id' => $this->customerId?->value,
            'node_id' => $this->nodeId->value,
            'business_date' => $this->businessDate->toString(),
            'grand_total' => $this->grandTotal()->toStorage(),
            'paid' => $this->paid->toStorage(),
            'credited' => $this->credited->toStorage(),
            'outstanding' => $this->outstanding()->toStorage(),
            'due_date' => $this->dueDate?->toString(),
            'status_reason' => $this->statusReason,
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function doReversePayment(Payment $payment, PaymentReversal $reversal, bool $withSale): void
    {
        if (! $payment->saleId->equals($this->id())) {
            throw InvariantViolation::because('PAYMENT_NOT_OF_SALE', 'The payment belongs to another sale.');
        }
        if (! $this->status->isFinal()) {
            throw StateConflict::because('SALE_NOT_PAYABLE', "Sale {$this->number->toString()} is {$this->status->value}.", ['status' => $this->status->value]);
        }
        if ($this->customerId === null && ! $withSale) {
            throw StateConflict::because('WALK_IN_MUST_BE_PAID',
                'A walk-in sale must stay fully paid; reverse the whole sale (with reverse_payments) instead of a single payment.');
        }
        $payment->reverse($reversal);
        $this->paid = $this->paid->minus($payment->amount);
        if ($this->status === SaleStatus::COMPLETED && ! $withSale) {
            $this->transitionTo(SaleStatus::CONFIRMED);
            $this->completedAt = null;
        }
    }

    private function settleIfPaid(DateTimeImmutable $at): void
    {
        if ($this->status === SaleStatus::CONFIRMED && $this->outstanding()->isZero()) {
            $this->transitionTo(SaleStatus::COMPLETED);
            $this->completedAt = $at;
        }
    }

    private function transitionTo(SaleStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw self::invalidTransition($this->status, $next);
        }
        $this->status = $next;
    }

    private static function invalidTransition(SaleStatus $from, SaleStatus $to): StateConflict
    {
        return StateConflict::because('INVALID_STATUS_TRANSITION', "Sale cannot go from {$from->value} to {$to->value}.",
            ['document' => 'Sale', 'from' => $from->value, 'to' => $to->value]);
    }
}
