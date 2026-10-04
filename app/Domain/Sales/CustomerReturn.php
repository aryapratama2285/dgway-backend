<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * Goods a customer brought back, referencing the original sale lines.
 * Created only through {@see Sale::recordReturn()} (the sale guards "never
 * more than sold − already returned"). Append-only.
 *
 * Money: the returned goods' value (net transaction price) first reduces
 * what the customer still owes (credit_amount); anything beyond that was
 * already paid and is refunded (refund_amount, with the refund method).
 * total_value = credit_amount + refund_amount.
 */
final class CustomerReturn extends AggregateRoot
{
    /** @param list<CustomerReturnLine> $lines */
    private function __construct(
        Id $id,
        public readonly DocumentNumber $number,
        public readonly Id $saleId,
        public readonly ?Id $customerId,
        public readonly Id $nodeId,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $returnedAt,
        public readonly Currency $currency,
        public readonly array $lines,
        public readonly Money $creditAmount,
        public readonly Money $refundAmount,
        public readonly ?PaymentMethod $refundMethod,
        public readonly string $reason,
        public readonly ?string $actorUserId,
    ) {
        parent::__construct($id, 1);
    }

    /** @param list<CustomerReturnLine> $lines @internal use Sale::recordReturn() */
    public static function create(Id $id, DocumentNumber $number, Id $saleId, ?Id $customerId, Id $nodeId, BusinessDate $date, DateTimeImmutable $at,
        Currency $currency, array $lines, Money $credit, Money $refund, ?PaymentMethod $refundMethod, string $reason, ?string $actorUserId): self
    {
        return new self($id, $number, $saleId, $customerId, $nodeId, $date, $at, $currency, $lines, $credit, $refund, $refundMethod, $reason, $actorUserId);
    }

    /** @param list<CustomerReturnLine> $lines */
    public static function reconstitute(Id $id, DocumentNumber $number, Id $saleId, ?Id $customerId, Id $nodeId, BusinessDate $date, DateTimeImmutable $at,
        Currency $currency, array $lines, Money $credit, Money $refund, ?PaymentMethod $refundMethod, string $reason, ?string $actorUserId): self
    {
        return new self($id, $number, $saleId, $customerId, $nodeId, $date, $at, $currency, $lines, $credit, $refund, $refundMethod, $reason, $actorUserId);
    }

    public function totalValue(): Money
    {
        return $this->creditAmount->plus($this->refundAmount);
    }

    public function snapshot(): array
    {
        return [
            'number' => $this->number->toString(),
            'sale_id' => $this->saleId->value,
            'total_value' => $this->totalValue()->toStorage(),
            'credit_amount' => $this->creditAmount->toStorage(),
            'refund_amount' => $this->refundAmount->toStorage(),
            'refund_method' => $this->refundMethod?->value,
            'lines' => array_map(fn (CustomerReturnLine $l) => [
                'sale_line_id' => $l->saleLineId->value, 'quantity' => $l->quantity->toStorage(),
                'disposition' => $l->disposition->value, 'value' => $l->value->toStorage(),
            ], $this->lines),
        ];
    }
}
