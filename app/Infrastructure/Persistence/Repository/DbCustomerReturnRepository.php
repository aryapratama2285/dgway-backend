<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Sales\CustomerReturn;
use App\Domain\Sales\CustomerReturnLine;
use App\Domain\Sales\CustomerReturnRepository;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Sales\ReturnDisposition;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\EntityNotFound;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class DbCustomerReturnRepository implements CustomerReturnRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): CustomerReturn
    {
        $row = $this->db->table('customer_returns')->where('id', $id->value)->first() ?? throw EntityNotFound::of('Customer return', $id->value);

        return $this->hydrate($row, $this->lineRows([$row->id])[$row->id] ?? []);
    }

    public function returnsOfSale(Id $saleId): array
    {
        $rows = $this->db->table('customer_returns')->where('sale_id', $saleId->value)->orderBy('created_at')->orderBy('id')->get()->all();
        $lines = $this->lineRows(array_map(fn ($r) => $r->id, $rows));

        return array_map(fn ($r) => $this->hydrate($r, $lines[$r->id] ?? []), $rows);
    }

    public function add(CustomerReturn $c): void
    {
        $this->db->table('customer_returns')->insert([
            'id' => $c->id()->value,
            'number' => $c->number->toString(),
            'sale_id' => $c->saleId->value,
            'customer_id' => $c->customerId?->value,
            'node_id' => $c->nodeId->value,
            'business_date' => $c->businessDate->toString(),
            'returned_at' => $c->returnedAt->format('Y-m-d H:i:s.uP'),
            'currency' => $c->currency->code,
            'total_value' => $c->totalValue()->toStorage(),
            'credit_amount' => $c->creditAmount->toStorage(),
            'refund_amount' => $c->refundAmount->toStorage(),
            'refund_method' => $c->refundMethod?->value,
            'reason' => $c->reason,
            'actor_user_id' => $c->actorUserId,
        ]);
        foreach ($c->lines as $l) {
            $this->db->table('customer_return_lines')->insert([
                'id' => $l->id->value,
                'customer_return_id' => $c->id()->value,
                'sale_line_id' => $l->saleLineId->value,
                'product_id' => $l->productId->value,
                'uom_code' => $l->quantity->uom->code,
                'quantity' => $l->quantity->toStorage(),
                'value' => $l->value->toStorage(),
                'disposition' => $l->disposition->value,
                'reason' => $l->reason,
            ]);
        }
    }

    /** @param list<object> $lineRows */
    private function hydrate(object $r, array $lineRows): CustomerReturn
    {
        $currency = Currency::of($r->currency);
        $lines = array_map(function (object $l) use ($currency) {
            $uom = $this->uoms->find($l->uom_code) ?? throw new RuntimeException("Unknown UOM {$l->uom_code}");

            return new CustomerReturnLine(Id::fromString($l->id), Id::fromString($l->sale_line_id), Id::fromString($l->product_id),
                Quantity::of(DbStockLedger::trim((string) $l->quantity), $uom), Money::of((string) $l->value, $currency),
                ReturnDisposition::from($l->disposition), $l->reason);
        }, $lineRows);

        return CustomerReturn::reconstitute(
            Id::fromString($r->id), DocumentNumber::parse($r->number), Id::fromString($r->sale_id), $r->customer_id ? Id::fromString($r->customer_id) : null,
            Id::fromString($r->node_id), BusinessDate::fromString((string) $r->business_date), new DateTimeImmutable((string) $r->returned_at),
            $currency, $lines, Money::of((string) $r->credit_amount, $currency), Money::of((string) $r->refund_amount, $currency),
            $r->refund_method ? PaymentMethod::from($r->refund_method) : null, $r->reason, $r->actor_user_id,
        );
    }

    /** @param list<string> $ids @return array<string, list<object>> */
    private function lineRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->db->table('customer_return_lines')->whereIn('customer_return_id', $ids)->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('customer_return_id')->map(fn ($g) => $g->all())->all();
    }
}
