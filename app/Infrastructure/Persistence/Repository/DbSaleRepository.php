<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleLine;
use App\Domain\Sales\SaleOrigin;
use App\Domain\Sales\SaleRepository;
use App\Domain\Sales\SaleStatus;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use RuntimeException;

final class DbSaleRepository implements SaleRepository
{
    private const LABEL = 'Sale';

    public function __construct(
        private readonly RepositorySupport $support,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): Sale
    {
        return $this->load($id, false);
    }

    public function getForUpdate(Id $id): Sale
    {
        return $this->load($id, true);
    }

    public function add(Sale $sale, ?string $actorUserId = null): void
    {
        $this->support->insert('sales', $this->header($sale) + [
            'number' => $sale->number->toString(),
            'origin' => $sale->origin->value,
            'order_reference_id' => $sale->orderReferenceId?->value,
            'customer_id' => $sale->customerId?->value,
            'node_id' => $sale->nodeId->value,
            'business_date' => $sale->businessDate->toString(),
            'currency' => $sale->currency->code,
            'subtotal' => $sale->subtotal()->toStorage(),
            'discount_total' => $sale->discountTotal()->toStorage(),
            'grand_total' => $sale->grandTotal()->toStorage(),
            'created_by' => $actorUserId,
        ], $sale, self::LABEL, 'sales_number_unique', $sale->number->toString());

        foreach ($sale->lines() as $l) {
            $this->support->db()->table('sale_lines')->insert([
                'id' => $l->id->value,
                'sale_id' => $sale->id()->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom_code' => $l->quantity->uom->code,
                'quantity' => $l->quantity->toStorage(),
                'unit_price' => $l->unitPrice->toStorage(),
                'line_subtotal' => $l->subtotal()->toStorage(),
                'discount_amount' => $l->discount->toStorage(),
                'line_total' => $l->total()->toStorage(),
            ]);
        }
    }

    public function save(Sale $sale, ?string $actorUserId = null): void
    {
        $actor = match ($sale->status()) {
            SaleStatus::REVERSED => ['reversed_by' => $actorUserId],
            default => [],
        };
        $row = $this->support->db()->table('sales')->where('id', $sale->id()->value)->first(['status', 'confirmed_by']);
        if ($row !== null && $row->status === SaleStatus::DRAFT->value && $sale->status()->isFinal()) {
            $actor['confirmed_by'] = $actorUserId;
        }
        $this->support->update('sales', $this->header($sale) + $actor, $sale, self::LABEL);

        foreach ($sale->lines() as $l) {
            $this->support->db()->table('sale_lines')->where('id', $l->id->value)
                ->where(fn ($q) => $q->where('returned_quantity', '<>', $l->returnedQuantity()->toStorage())->orWhere('returned_amount', '<>', $l->returnedAmount()->toStorage()))
                ->update(['returned_quantity' => $l->returnedQuantity()->toStorage(), 'returned_amount' => $l->returnedAmount()->toStorage()]);
        }
    }

    /** @param list<object> $lineRows */
    public function hydrate(object $r, array $lineRows): Sale
    {
        $currency = Currency::of($r->currency);
        $lines = array_map(function (object $l) use ($currency): SaleLine {
            $uom = $this->uoms->find($l->uom_code) ?? throw new RuntimeException("Unknown UOM {$l->uom_code}");

            return SaleLine::reconstitute(
                Id::fromString($l->id), (int) $l->line_no, Id::fromString($l->product_id),
                Quantity::of(DbStockLedger::trim((string) $l->quantity), $uom),
                Money::of((string) $l->unit_price, $currency), Money::of((string) $l->discount_amount, $currency),
                Quantity::of(DbStockLedger::trim((string) $l->returned_quantity), $uom), Money::of((string) $l->returned_amount, $currency),
            );
        }, $lineRows);

        return Sale::reconstitute(
            Id::fromString($r->id), (int) $r->version, DocumentNumber::parse($r->number), SaleOrigin::from($r->origin),
            $r->order_reference_id ? Id::fromString($r->order_reference_id) : null,
            $r->customer_id ? Id::fromString($r->customer_id) : null, Id::fromString($r->node_id),
            BusinessDate::fromString((string) $r->business_date), $currency, $lines, SaleStatus::from($r->status),
            Money::of((string) $r->paid_amount, $currency), Money::of((string) $r->credited_amount, $currency),
            $r->payment_term_days === null ? null : (int) $r->payment_term_days,
            $r->due_date ? BusinessDate::fromString((string) $r->due_date) : null,
            self::time($r->confirmed_at), self::time($r->completed_at), self::time($r->reversed_at), $r->status_reason, $r->notes,
        );
    }

    /** @param list<string> $saleIds @return array<string, list<object>> */
    public function lineRows(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        return $this->support->db()->table('sale_lines')->whereIn('sale_id', $saleIds)->orderBy('line_no')->get()
            ->groupBy('sale_id')->map(fn ($g) => $g->all())->all();
    }

    /** @return array<string, mixed> */
    private function header(Sale $sale): array
    {
        return [
            'status' => $sale->status()->value,
            'paid_amount' => $sale->paid()->toStorage(),
            'credited_amount' => $sale->credited()->toStorage(),
            'payment_term_days' => $sale->termDays(),
            'due_date' => $sale->dueDate()?->toString(),
            'confirmed_at' => $sale->confirmedAt()?->format('Y-m-d H:i:s.uP'),
            'completed_at' => $sale->completedAt()?->format('Y-m-d H:i:s.uP'),
            'reversed_at' => $sale->reversedAt()?->format('Y-m-d H:i:s.uP'),
            'status_reason' => $sale->statusReason(),
            'notes' => $sale->notes(),
        ];
    }

    private function load(Id $id, bool $lock): Sale
    {
        $row = $this->support->fetch('sales', $id->value, self::LABEL, $lock);

        return $this->hydrate($row, $this->lineRows([$id->value])[$id->value] ?? []);
    }

    private static function time(mixed $v): ?DateTimeImmutable
    {
        return $v === null ? null : new DateTimeImmutable((string) $v);
    }
}
