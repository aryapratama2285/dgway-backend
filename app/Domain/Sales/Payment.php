<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\StateConflict;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * Money received for one sale. Its own aggregate (own identity, own
 * history), but only created through {@see Sale::recordPayment()} because
 * "never more than outstanding" is an invariant of the sale.
 *
 * A payment is never edited or deleted. A wrong payment gets a
 * PaymentReversal record; a reversed payment cannot be reversed again.
 */
final class Payment extends AggregateRoot
{
    private function __construct(
        Id $id,
        public readonly DocumentNumber $number,
        public readonly Id $saleId,
        public readonly ?Id $customerId,
        public readonly PaymentMethod $method,
        public readonly Money $amount,
        public readonly DateTimeImmutable $paidAt,
        public readonly BusinessDate $businessDate,
        public readonly ?string $reference,
        public readonly ?string $notes,
        public readonly ?string $actorUserId,
        private ?PaymentReversal $reversal,
    ) {
        parent::__construct($id, 1); // append-only record: never versioned
    }

    /** @internal use Sale::recordPayment() */
    public static function fromRequest(PaymentRequest $r, Id $saleId, ?Id $customerId): self
    {
        $reference = $r->reference === null ? null : (trim($r->reference) ?: null);
        $notes = $r->notes === null ? null : (trim($r->notes) ?: null);

        return new self($r->id, $r->number, $saleId, $customerId, $r->method, $r->amount, $r->paidAt, $r->businessDate,
            $reference === null ? null : mb_substr($reference, 0, 100), $notes === null ? null : mb_substr($notes, 0, 500), $r->actorUserId, null);
    }

    public static function reconstitute(Id $id, DocumentNumber $number, Id $saleId, ?Id $customerId, PaymentMethod $method, Money $amount,
        DateTimeImmutable $paidAt, BusinessDate $businessDate, ?string $reference, ?string $notes, ?string $actorUserId, ?PaymentReversal $reversal): self
    {
        return new self($id, $number, $saleId, $customerId, $method, $amount, $paidAt, $businessDate, $reference, $notes, $actorUserId, $reversal);
    }

    /** @internal use Sale::reversePayment() so the sale's outstanding follows */
    public function reverse(PaymentReversal $reversal): void
    {
        if ($this->reversal !== null) {
            throw StateConflict::because('PAYMENT_ALREADY_REVERSED', "Payment {$this->number->toString()} is already reversed.", ['payment_id' => $this->id()->value]);
        }
        $this->reversal = $reversal;
    }

    public function isReversed(): bool
    {
        return $this->reversal !== null;
    }

    public function reversal(): ?PaymentReversal
    {
        return $this->reversal;
    }

    public function status(): string
    {
        return $this->reversal === null ? 'POSTED' : 'REVERSED';
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'sale_id' => $this->saleId->value,
            'method' => $this->method->value,
            'amount' => $this->amount->toStorage(),
            'business_date' => $this->businessDate->toString(),
            'reference' => $this->reference,
            'status' => $this->status(),
            'reversal_reason' => $this->reversal?->reason,
        ];
    }
}
