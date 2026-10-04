<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Sales\SalesQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Owner-app read models. Aggregation happens in PostgreSQL; lists are paged
 * with LIMIT/OFFSET and a COUNT. Receivable queries only touch open sales
 * through the partial indexes on `outstanding > 0`.
 */
final class DbSalesQueries implements SalesQueries
{
    private const PAYMENT_STATUS_SQL = "CASE WHEN s.status NOT IN ('CONFIRMED','COMPLETED') THEN 'NONE'
        WHEN s.outstanding = 0 THEN 'PAID' WHEN s.paid_amount > 0 OR s.credited_amount > 0 THEN 'PARTIALLY_PAID' ELSE 'UNPAID' END";

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function sales(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('sales as s')->leftJoin('business_partners as c', 'c.id', '=', 's.customer_id')
            ->select('s.*', 'c.code as customer_code', 'c.name as customer_name')
            ->selectRaw(self::PAYMENT_STATUS_SQL.' AS payment_status');
        $this->dates($q, 's.business_date', $filters);
        foreach (['customer_id', 'node_id'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("s.{$f}", $filters[$f]);
            }
        }
        if (! empty($filters['status'])) {
            $q->where('s.status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['payment_status'])) {
            $q->whereRaw(self::PAYMENT_STATUS_SQL.' = ?', [strtoupper((string) $filters['payment_status'])]);
        }
        if (! empty($filters['walk_in'])) {
            $q->whereNull('s.customer_id');
        }
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])).'%';
            $q->where(fn (Builder $w) => $w->where('s.number', 'ilike', $term)->orWhere('c.name', 'ilike', $term)->orWhere('c.code', 'ilike', $term));
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('s.business_date')->orderByDesc('s.number')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'business_date' => (string) $r->business_date,
            'customer' => $r->customer_id === null ? null : ['id' => $r->customer_id, 'code' => $r->customer_code, 'name' => $r->customer_name],
            'node_id' => $r->node_id,
            'grand_total' => (string) $r->grand_total,
            'paid' => (string) $r->paid_amount,
            'credited' => (string) $r->credited_amount,
            'outstanding' => (string) $r->outstanding,
            'due_date' => $r->due_date === null ? null : (string) $r->due_date,
            'version' => (int) $r->version,
        ], $rows), $total, $page);
    }

    public function payments(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('payments as p')
            ->join('sales as s', 's.id', '=', 'p.sale_id')
            ->leftJoin('business_partners as c', 'c.id', '=', 'p.customer_id')
            ->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')
            ->select('p.*', 's.number as sale_number', 'c.code as customer_code', 'c.name as customer_name', 'r.reason as reversal_reason', 'r.reversed_at');
        $this->dates($q, 'p.business_date', $filters);
        foreach (['sale_id', 'customer_id'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("p.{$f}", $filters[$f]);
            }
        }
        if (! empty($filters['method'])) {
            $q->where('p.method', strtoupper((string) $filters['method']));
        }
        if (! empty($filters['status'])) {
            strtoupper((string) $filters['status']) === 'REVERSED' ? $q->whereNotNull('r.id') : $q->whereNull('r.id');
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('p.paid_at')->orderByDesc('p.number')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->reversal_reason === null ? 'POSTED' : 'REVERSED',
            'sale_id' => $r->sale_id,
            'sale_number' => $r->sale_number,
            'customer' => $r->customer_id === null ? null : ['id' => $r->customer_id, 'code' => $r->customer_code, 'name' => $r->customer_name],
            'method' => $r->method,
            'amount' => (string) $r->amount,
            'currency' => $r->currency,
            'paid_at' => (new \DateTimeImmutable((string) $r->paid_at))->format(DATE_ATOM),
            'business_date' => (string) $r->business_date,
            'reference' => $r->reference,
            'reversal_reason' => $r->reversal_reason,
        ], $rows), $total, $page);
    }

    public function customerReturns(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('customer_returns as cr')->join('sales as s', 's.id', '=', 'cr.sale_id')
            ->leftJoin('business_partners as c', 'c.id', '=', 'cr.customer_id')
            ->select('cr.*', 's.number as sale_number', 'c.name as customer_name');
        $this->dates($q, 'cr.business_date', $filters);
        foreach (['sale_id', 'customer_id'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("cr.{$f}", $filters[$f]);
            }
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('cr.returned_at')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'id' => $r->id, 'number' => $r->number, 'sale_id' => $r->sale_id, 'sale_number' => $r->sale_number,
            'customer_id' => $r->customer_id, 'customer_name' => $r->customer_name, 'business_date' => (string) $r->business_date,
            'total_value' => (string) $r->total_value, 'credit_amount' => (string) $r->credit_amount,
            'refund_amount' => (string) $r->refund_amount, 'refund_method' => $r->refund_method, 'reason' => $r->reason,
        ], $rows), $total, $page);
    }

    public function receivablesBySale(array $filters, string $today, PageRequest $page): Page
    {
        $q = $this->db->table('sales as s')->join('business_partners as c', 'c.id', '=', 's.customer_id')
            ->where('s.outstanding', '>', 0)
            ->select('s.id', 's.number', 's.business_date', 's.due_date', 's.grand_total', 's.paid_amount', 's.credited_amount', 's.outstanding',
                's.customer_id', 'c.code as customer_code', 'c.name as customer_name', 'c.phone as customer_phone')
            ->selectRaw('GREATEST(?::date - s.due_date, 0) AS days_overdue', [$today]);
        if (! empty($filters['customer_id'])) {
            $q->where('s.customer_id', $filters['customer_id']);
        }
        if (! empty($filters['overdue'])) {
            $q->where('s.due_date', '<', $today);
        }
        if (! empty($filters['due_on_or_before'])) {
            $q->where('s.due_date', '<=', $filters['due_on_or_before']);
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('s.due_date')->orderBy('s.number')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'sale_id' => $r->id,
            'sale_number' => $r->number,
            'customer' => ['id' => $r->customer_id, 'code' => $r->customer_code, 'name' => $r->customer_name, 'phone' => $r->customer_phone],
            'business_date' => (string) $r->business_date,
            'due_date' => (string) $r->due_date,
            'days_overdue' => (int) $r->days_overdue,
            'status' => $this->dueStatus((string) $r->due_date, $today),
            'grand_total' => (string) $r->grand_total,
            'paid' => (string) $r->paid_amount,
            'credited' => (string) $r->credited_amount,
            'outstanding' => (string) $r->outstanding,
        ], $rows), $total, $page);
    }

    public function receivablesByCustomer(array $filters, string $today, PageRequest $page): Page
    {
        $q = $this->db->table('sales as s')->join('business_partners as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('business_partner_roles as r', fn ($j) => $j->on('r.partner_id', '=', 'c.id')->where('r.role', '=', 'CUSTOMER'))
            ->where('s.outstanding', '>', 0)
            ->groupBy('c.id', 'c.code', 'c.name', 'c.phone', 'r.credit_limit', 'r.payment_term_days')
            ->select('c.id', 'c.code', 'c.name', 'c.phone', 'r.credit_limit', 'r.payment_term_days')
            ->selectRaw('sum(s.outstanding) AS outstanding, count(*) AS open_sales, min(s.due_date) AS oldest_due_date')
            ->selectRaw('COALESCE(sum(s.outstanding) FILTER (WHERE s.due_date < ?::date), 0) AS overdue', [$today])
            ->selectRaw('COALESCE(sum(s.outstanding) FILTER (WHERE s.due_date = ?::date), 0) AS due_today', [$today])
            ->selectRaw('CASE WHEN r.credit_limit IS NULL THEN NULL ELSE GREATEST(r.credit_limit - sum(s.outstanding), 0)::numeric(19,4) END AS available_credit');
        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $filters['q'])).'%';
            $q->where(fn (Builder $w) => $w->where('c.name', 'ilike', $term)->orWhere('c.code', 'ilike', $term)->orWhere('c.phone', 'ilike', $term));
        }
        if (! empty($filters['overdue'])) {
            $q->havingRaw('sum(s.outstanding) FILTER (WHERE s.due_date < ?::date) > 0', [$today]);
        }
        $total = (int) $this->db->query()->fromSub($q, 'x')->count();
        $rows = $q->orderByDesc('outstanding')->orderBy('c.code')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'customer' => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'phone' => $r->phone],
            'outstanding' => (string) $r->outstanding,
            'overdue' => (string) $r->overdue,
            'due_today' => (string) $r->due_today,
            'open_sales' => (int) $r->open_sales,
            'oldest_due_date' => (string) $r->oldest_due_date,
            'payment_term_days' => $r->payment_term_days === null ? null : (int) $r->payment_term_days,
            'credit_limit' => $r->credit_limit === null ? null : (string) $r->credit_limit,
            'available_credit' => $r->available_credit === null ? null : (string) $r->available_credit,
        ], $rows), $total, $page);
    }

    public function receivableSummary(string $today): array
    {
        $r = $this->db->selectOne(<<<'SQL'
            SELECT COALESCE(sum(outstanding), 0)::numeric(19,4) AS total,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date > ?::date), 0)::numeric(19,4) AS not_due,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date = ?::date), 0)::numeric(19,4) AS due_today,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date < ?::date), 0)::numeric(19,4) AS overdue,
                   count(DISTINCT customer_id) AS customers,
                   count(DISTINCT customer_id) FILTER (WHERE due_date < ?::date) AS overdue_customers,
                   count(*) AS open_sales
              FROM sales WHERE outstanding > 0
        SQL, [$today, $today, $today, $today]);

        return [
            'as_of' => $today,
            'total' => (string) $r->total,
            'not_yet_due' => (string) $r->not_due,
            'due_today' => (string) $r->due_today,
            'overdue' => (string) $r->overdue,
            'customers' => (int) $r->customers,
            'overdue_customers' => (int) $r->overdue_customers,
            'open_sales' => (int) $r->open_sales,
        ];
    }

    public function salesSummary(string $dateFrom, string $dateTo, string $today): array
    {
        $s = $this->db->selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE status IN ('CONFIRMED','COMPLETED')) AS sales,
                   COALESCE(sum(subtotal) FILTER (WHERE status IN ('CONFIRMED','COMPLETED')), 0)::numeric(19,4) AS subtotal,
                   COALESCE(sum(discount_total) FILTER (WHERE status IN ('CONFIRMED','COMPLETED')), 0)::numeric(19,4) AS discount,
                   COALESCE(sum(grand_total) FILTER (WHERE status IN ('CONFIRMED','COMPLETED')), 0)::numeric(19,4) AS grand_total,
                   count(*) FILTER (WHERE status IN ('CONFIRMED','COMPLETED') AND customer_id IS NULL) AS walk_in_sales,
                   count(*) FILTER (WHERE status = 'REVERSED') AS reversed_sales,
                   COALESCE(sum(grand_total) FILTER (WHERE status = 'REVERSED'), 0)::numeric(19,4) AS reversed_amount,
                   count(*) FILTER (WHERE status = 'DRAFT') AS draft_sales
              FROM sales WHERE business_date BETWEEN ?::date AND ?::date
        SQL, [$dateFrom, $dateTo]);
        $returns = $this->db->selectOne(<<<'SQL'
            SELECT count(*) AS n, COALESCE(sum(total_value), 0)::numeric(19,4) AS value,
                   COALESCE(sum(refund_amount), 0)::numeric(19,4) AS refunds
              FROM customer_returns WHERE business_date BETWEEN ?::date AND ?::date
        SQL, [$dateFrom, $dateTo]);
        $netSales = (string) $this->db->selectOne('SELECT (?::numeric - ?::numeric)::numeric(19,4) AS v', [$s->grand_total, $returns->value])->v;

        $methods = ['CASH', 'BANK_TRANSFER', 'QRIS'];
        $byMethod = array_fill_keys($methods, ['received' => '0.0000', 'reversed' => '0.0000', 'refunded' => '0.0000']);
        foreach ($this->db->select('SELECT method, sum(amount)::numeric(19,4) AS v, count(*) AS n FROM payments WHERE business_date BETWEEN ?::date AND ?::date GROUP BY method', [$dateFrom, $dateTo]) as $r) {
            $byMethod[$r->method]['received'] = (string) $r->v;
        }
        foreach ($this->db->select('SELECT p.method, sum(p.amount)::numeric(19,4) AS v FROM payment_reversals x JOIN payments p ON p.id = x.payment_id WHERE x.business_date BETWEEN ?::date AND ?::date GROUP BY p.method', [$dateFrom, $dateTo]) as $r) {
            $byMethod[$r->method]['reversed'] = (string) $r->v;
        }
        foreach ($this->db->select('SELECT refund_method AS method, sum(refund_amount)::numeric(19,4) AS v FROM customer_returns WHERE refund_amount > 0 AND business_date BETWEEN ?::date AND ?::date GROUP BY refund_method', [$dateFrom, $dateTo]) as $r) {
            $byMethod[$r->method]['refunded'] = (string) $r->v;
        }
        $cash = $this->db->selectOne(<<<'SQL'
            SELECT (SELECT COALESCE(sum(amount), 0) FROM payments WHERE business_date BETWEEN ?::date AND ?::date)::numeric(19,4) AS received,
                   (SELECT COALESCE(sum(p.amount), 0) FROM payment_reversals x JOIN payments p ON p.id = x.payment_id WHERE x.business_date BETWEEN ?::date AND ?::date)::numeric(19,4) AS reversed,
                   (SELECT count(*) FROM payments WHERE business_date BETWEEN ?::date AND ?::date) AS n
        SQL, [$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]);
        $net = (string) $this->db->selectOne('SELECT (?::numeric - ?::numeric - ?::numeric)::numeric(19,4) AS v', [$cash->received, $cash->reversed, $returns->refunds])->v;

        return [
            'period' => ['from' => $dateFrom, 'to' => $dateTo],
            'sales' => [
                'count' => (int) $s->sales,
                'walk_in_count' => (int) $s->walk_in_sales,
                'subtotal' => (string) $s->subtotal,
                'discount' => (string) $s->discount,
                'grand_total' => (string) $s->grand_total,
                'returns_value' => (string) $returns->value,
                'net_sales' => $netSales,
                'reversed_count' => (int) $s->reversed_sales,
                'reversed_amount' => (string) $s->reversed_amount,
                'draft_count' => (int) $s->draft_sales,
            ],
            'payments' => [
                'count' => (int) $cash->n,
                'received' => (string) $cash->received,
                'reversed' => (string) $cash->reversed,
                'refunded' => (string) $returns->refunds,
                'net_received' => $net,
                'by_method' => $byMethod,
            ],
            'receivables' => $this->receivableSummary($today),
        ];
    }

    public function salesByProduct(string $dateFrom, string $dateTo): array
    {
        $rows = $this->db->select(<<<'SQL'
            WITH sold AS (
                SELECT l.product_id, l.uom_code, sum(l.quantity) AS qty, sum(l.line_total) AS revenue,
                       sum(l.returned_quantity) AS returned_qty, sum(l.returned_amount) AS returned_value, count(DISTINCT s.id) AS sales
                  FROM sale_lines l JOIN sales s ON s.id = l.sale_id
                 WHERE s.status IN ('CONFIRMED','COMPLETED') AND s.business_date BETWEEN ?::date AND ?::date
                 GROUP BY l.product_id, l.uom_code
            ), bought AS (
                SELECT gl.product_id, sum(gl.received_quantity) AS qty, sum(gl.line_cost) AS cost
                  FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
                 WHERE g.status = 'POSTED' AND g.business_date BETWEEN ?::date AND ?::date
                 GROUP BY gl.product_id
            )
            SELECT p.id, p.sku, p.name, sold.uom_code, sold.sales, sold.qty, sold.revenue, sold.returned_qty, sold.returned_value,
                   (sold.qty - sold.returned_qty)::numeric(18,4) AS net_qty, (sold.revenue - sold.returned_value)::numeric(19,4) AS net_revenue,
                   CASE WHEN sold.qty > 0 THEN round(sold.revenue / sold.qty, 4) END AS avg_unit_price,
                   bought.qty AS purchased_qty, bought.cost AS purchase_cost,
                   CASE WHEN bought.qty > 0 THEN round(bought.cost / bought.qty, 4) END AS avg_unit_cost
              FROM sold JOIN products p ON p.id = sold.product_id LEFT JOIN bought ON bought.product_id = sold.product_id
             ORDER BY sold.revenue DESC, p.sku
        SQL, [$dateFrom, $dateTo, $dateFrom, $dateTo]);

        $s = fn ($v) => $v === null ? null : (string) $v;

        return array_map(fn ($r) => [
            'product_id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'uom' => $r->uom_code, 'sales' => (int) $r->sales,
            'quantity_sold' => $s($r->qty), 'revenue' => $s($r->revenue), 'returned_quantity' => $s($r->returned_qty), 'returned_value' => $s($r->returned_value),
            'net_quantity' => $s($r->net_qty), 'net_revenue' => $s($r->net_revenue), 'avg_unit_price' => $s($r->avg_unit_price),
            // raw acquisition figures for the same period (A3); no COGS/HPP policy applied
            'purchase' => ['received_quantity' => $s($r->purchased_qty), 'purchase_cost' => $s($r->purchase_cost), 'avg_unit_cost' => $s($r->avg_unit_cost)],
        ], $rows);
    }

    public function customerLabel(?string $customerId): ?array
    {
        if ($customerId === null) {
            return null;
        }
        $r = $this->db->table('business_partners')->where('id', $customerId)->first(['id', 'code', 'name', 'phone']);

        return $r === null ? null : ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'phone' => $r->phone];
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $q, string $column, array $filters): void
    {
        if (! empty($filters['date_from'])) {
            $q->where($column, '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where($column, '<=', $filters['date_to']);
        }
    }

    private function dueStatus(string $due, string $today): string
    {
        return match ($due <=> $today) {
            -1 => 'OVERDUE',
            0 => 'DUE_TODAY',
            default => 'NOT_YET_DUE',
        };
    }
}
