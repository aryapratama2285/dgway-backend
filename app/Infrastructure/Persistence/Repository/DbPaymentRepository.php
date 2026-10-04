<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Sales\Payment;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\PaymentReversal;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\EntityNotFound;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use LogicException;

/** payments + payment_reversals: both append-only (trigger-protected). */
final class DbPaymentRepository implements PaymentRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function get(Id $id): Payment
    {
        $row = $this->query()->where('p.id', $id->value)->first() ?? throw EntityNotFound::of('Payment', $id->value);

        return $this->hydrate($row);
    }

    public function validPaymentsOfSale(Id $saleId): array
    {
        return array_map(fn ($r) => $this->hydrate($r), $this->query()->where('p.sale_id', $saleId->value)->whereNull('r.id')->orderBy('p.created_at')->orderBy('p.id')->get()->all());
    }

    public function paymentsOfSale(Id $saleId): array
    {
        return array_map(fn ($r) => $this->hydrate($r), $this->query()->where('p.sale_id', $saleId->value)->orderBy('p.created_at')->orderBy('p.id')->get()->all());
    }

    public function add(Payment $p): void
    {
        $this->db->table('payments')->insert([
            'id' => $p->id()->value,
            'number' => $p->number->toString(),
            'sale_id' => $p->saleId->value,
            'customer_id' => $p->customerId?->value,
            'method' => $p->method->value,
            'amount' => $p->amount->toStorage(),
            'currency' => $p->amount->currency->code,
            'paid_at' => $p->paidAt->format('Y-m-d H:i:s.uP'),
            'business_date' => $p->businessDate->toString(),
            'reference' => $p->reference,
            'notes' => $p->notes,
            'actor_user_id' => $p->actorUserId,
        ]);
    }

    public function addReversal(Payment $p): void
    {
        $r = $p->reversal() ?? throw new LogicException('Payment has no reversal to store.');
        $this->db->table('payment_reversals')->insert([
            'id' => $r->id->value,
            'payment_id' => $p->id()->value,
            'sale_id' => $p->saleId->value,
            'reason' => $r->reason,
            'reversed_at' => $r->reversedAt->format('Y-m-d H:i:s.uP'),
            'business_date' => $r->businessDate->toString(),
            'actor_user_id' => $r->actorUserId,
        ]);
    }

    public function hydrate(object $r): Payment
    {
        $currency = Currency::of($r->currency);
        $reversal = $r->reversal_id === null ? null : new PaymentReversal(
            Id::fromString($r->reversal_id), $r->reversal_reason, new DateTimeImmutable((string) $r->reversed_at),
            BusinessDate::fromString((string) $r->reversal_business_date), $r->reversal_actor,
        );

        return Payment::reconstitute(
            Id::fromString($r->id), DocumentNumber::parse($r->number), Id::fromString($r->sale_id), $r->customer_id ? Id::fromString($r->customer_id) : null,
            PaymentMethod::from($r->method), Money::of((string) $r->amount, $currency), new DateTimeImmutable((string) $r->paid_at),
            BusinessDate::fromString((string) $r->business_date), $r->reference, $r->notes, $r->actor_user_id, $reversal,
        );
    }

    public function query(): Builder
    {
        return $this->db->table('payments as p')->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')
            ->select('p.*', 'r.id as reversal_id', 'r.reason as reversal_reason', 'r.reversed_at', 'r.business_date as reversal_business_date', 'r.actor_user_id as reversal_actor');
    }
}
