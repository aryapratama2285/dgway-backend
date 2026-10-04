<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Operations\OperationsQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use Illuminate\Database\ConnectionInterface;

/**
 * SQL read models for the owner dashboard, activity feed, search and
 * operational defaults. Timestamps are converted to business dates in the
 * configured business timezone INSIDE SQL ((ts AT TIME ZONE tz)::date), so
 * the database session timezone never shifts a transaction to another day.
 */
final class DbOperationsQueries implements OperationsQueries
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    private function tz(): string
    {
        return (string) config('dgway.business_timezone', 'Asia/Jakarta');
    }

    public function receivablesAsOf(string $date): array
    {
        $r = $this->db->selectOne(<<<'SQL'
            WITH s AS (
                SELECT s.id, s.customer_id, s.due_date, s.grand_total
                  FROM sales s
                 WHERE s.confirmed_at IS NOT NULL AND (s.confirmed_at AT TIME ZONE :tz)::date <= :d
                   AND (s.reversed_at IS NULL OR (s.reversed_at AT TIME ZONE :tz)::date > :d)
            ), paid AS (
                SELECT p.sale_id, sum(p.amount) AS amt FROM payments p
                 WHERE p.business_date <= :d
                   AND NOT EXISTS (SELECT 1 FROM payment_reversals x WHERE x.payment_id = p.id AND x.business_date <= :d)
                 GROUP BY p.sale_id
            ), credited AS (
                SELECT sale_id, sum(credit_amount) AS amt FROM customer_returns WHERE business_date <= :d GROUP BY sale_id
            ), o AS (
                SELECT s.customer_id, s.due_date, s.grand_total - COALESCE(paid.amt, 0) - COALESCE(credited.amt, 0) AS outstanding
                  FROM s LEFT JOIN paid ON paid.sale_id = s.id LEFT JOIN credited ON credited.sale_id = s.id
            )
            SELECT COALESCE(sum(outstanding), 0)::numeric(19,4) AS total,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date > :d), 0)::numeric(19,4) AS not_due,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date = :d), 0)::numeric(19,4) AS due_today,
                   COALESCE(sum(outstanding) FILTER (WHERE due_date < :d), 0)::numeric(19,4) AS overdue,
                   count(*) AS open_sales, count(DISTINCT customer_id) AS customers
              FROM o WHERE outstanding > 0
        SQL, ['tz' => $this->tz(), 'd' => $date]);

        return [
            'as_of' => $date,
            'total' => (string) $r->total,
            'not_yet_due' => (string) $r->not_due,
            'due_today' => (string) $r->due_today,
            'overdue' => (string) $r->overdue,
            'open_sales' => (int) $r->open_sales,
            'customers' => (int) $r->customers,
        ];
    }

    public function purchasing(string $date): array
    {
        $po = $this->db->selectOne(<<<'SQL'
            SELECT count(DISTINCT o.id) AS n, COALESCE(sum(l.line_total), 0)::numeric(19,4) AS total
              FROM purchase_orders o JOIN purchase_order_lines l ON l.purchase_order_id = o.id
             WHERE o.business_date = ? AND o.status NOT IN ('DRAFT', 'CANCELLED')
        SQL, [$date]);
        $gr = $this->db->selectOne(<<<'SQL'
            SELECT count(DISTINCT g.id) AS n, COALESCE(sum(l.line_cost), 0)::numeric(19,4) AS cost
              FROM goods_receipts g JOIN goods_receipt_lines l ON l.goods_receipt_id = g.id
             WHERE g.business_date = ? AND g.status = 'POSTED'
        SQL, [$date]);
        $received = $this->db->select(<<<'SQL'
            SELECT l.uom_code, sum(l.received_quantity)::numeric(18,4) AS received
              FROM goods_receipts g JOIN goods_receipt_lines l ON l.goods_receipt_id = g.id
             WHERE g.business_date = ? AND g.status = 'POSTED' GROUP BY l.uom_code ORDER BY 1
        SQL, [$date]);
        $byType = [];
        foreach ($this->db->select(<<<'SQL'
            SELECT l.uom_code, d.disposition_type, sum(d.quantity)::numeric(18,4) AS q
              FROM goods_receipts g JOIN goods_receipt_lines l ON l.goods_receipt_id = g.id
              JOIN goods_receipt_dispositions d ON d.goods_receipt_line_id = l.id
             WHERE g.business_date = ? AND g.status = 'POSTED' GROUP BY 1, 2
        SQL, [$date]) as $r) {
            $byType[$r->uom_code][strtolower($r->disposition_type)] = (string) $r->q;
        }

        return [
            'purchase_orders' => ['count' => (int) $po->n, 'total' => (string) $po->total],
            'goods_receipts' => ['count' => (int) $gr->n, 'cost' => (string) $gr->cost],
            'received_quantity' => array_map(fn ($r) => ['uom' => $r->uom_code, 'received' => (string) $r->received] + array_merge(
                ['accept' => '0.0000', 'loss' => '0.0000', 'return_to_supplier' => '0.0000', 'regrade' => '0.0000'], $byType[$r->uom_code] ?? []), $received),
        ];
    }

    public function inventoryAsOf(string $date): array
    {
        $stock = $this->db->select(<<<'SQL'
            SELECT uom_code, bucket, sum(signed_quantity)::numeric(18,4) AS q
              FROM stock_movements WHERE business_date <= ? GROUP BY uom_code, bucket ORDER BY 1, 2
        SQL, [$date]);
        $byUom = [];
        foreach ($stock as $r) {
            $byUom[$r->uom_code] ??= ['uom' => $r->uom_code, 'available' => '0.0000', 'quarantine' => '0.0000', 'return_pending' => '0.0000'];
            $byUom[$r->uom_code][strtolower($r->bucket)] = (string) $r->q;
        }
        $day = $this->db->selectOne(<<<'SQL'
            SELECT COALESCE(sum(quantity) FILTER (WHERE movement_type = 'LOSS'), 0)::numeric(18,4) AS loss,
                   COALESCE(sum(quantity) FILTER (WHERE movement_type = 'SALE'), 0)::numeric(18,4) AS sold
              FROM stock_movements WHERE business_date = ?
        SQL, [$date]);
        $products = (int) $this->db->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM (SELECT product_id FROM stock_movements WHERE business_date <= ? AND bucket = 'AVAILABLE'
                                        GROUP BY product_id, node_id HAVING sum(signed_quantity) > 0) x
        SQL, [$date])->n;
        $lots = (int) $this->db->selectOne('SELECT count(DISTINCT lot_id) AS n FROM stock_lot_balances WHERE quantity > 0')->n;

        return [
            'as_of' => $date,
            'stock_by_uom' => array_values($byUom),
            'products_in_stock' => $products,
            'losses_on_date' => (string) $day->loss,
            'sold_quantity_on_date' => (string) $day->sold,
            // lot balances are a current projection (not dated): active lots NOW
            'active_lots_now' => $lots,
        ];
    }

    public function activity(string $date, ?string $type, PageRequest $page): Page
    {
        $sql = <<<'SQL'
            SELECT 'PURCHASE_ORDER' AS type, o.created_at AS occurred_at, 'purchase_order' AS doc_type, o.id AS doc_id, o.number,
                   (SELECT sum(line_total) FROM purchase_order_lines WHERE purchase_order_id = o.id) AS amount,
                   (SELECT sum(ordered_quantity) FROM purchase_order_lines WHERE purchase_order_id = o.id) AS quantity,
                   bp.code AS party_code, bp.name AS party_name, NULL AS method, o.created_by AS actor_user_id, o.status AS detail
              FROM purchase_orders o JOIN business_partners bp ON bp.id = o.supplier_id
             WHERE o.business_date = :d AND o.status <> 'DRAFT'
            UNION ALL
            SELECT 'GOODS_RECEIPT', g.posted_at, 'goods_receipt', g.id, g.number,
                   (SELECT sum(line_cost) FROM goods_receipt_lines WHERE goods_receipt_id = g.id),
                   (SELECT sum(received_quantity) FROM goods_receipt_lines WHERE goods_receipt_id = g.id),
                   bp.code, bp.name, NULL, g.posted_by, g.status
              FROM goods_receipts g JOIN business_partners bp ON bp.id = g.supplier_id
             WHERE g.business_date = :d AND g.posted_at IS NOT NULL
            UNION ALL
            SELECT 'LOSS', min(m.occurred_at), min(m.source_document_type), m.source_document_id,
                   COALESCE(min(g.number), min(io.number), min(cr.number)), NULL, sum(m.quantity), NULL, NULL, NULL, min(m.actor_user_id::text)::uuid, min(m.reason)
              FROM stock_movements m
              LEFT JOIN goods_receipts g ON m.source_document_type = 'goods_receipt' AND g.id = m.source_document_id
              LEFT JOIN inventory_operations io ON m.source_document_type = 'inventory_operation' AND io.id = m.source_document_id
              LEFT JOIN customer_returns cr ON m.source_document_type = 'customer_return' AND cr.id = m.source_document_id
             WHERE m.business_date = :d AND m.movement_type = 'LOSS'
             GROUP BY m.source_document_id
            UNION ALL
            SELECT 'SALE', s.confirmed_at, 'sale', s.id, s.number, s.grand_total, (SELECT sum(quantity) FROM sale_lines WHERE sale_id = s.id),
                   bp.code, bp.name, NULL, s.confirmed_by, s.status
              FROM sales s LEFT JOIN business_partners bp ON bp.id = s.customer_id
             WHERE s.confirmed_at IS NOT NULL AND (s.confirmed_at AT TIME ZONE :tz)::date = :d
            UNION ALL
            SELECT 'PAYMENT', p.paid_at, 'payment', p.id, p.number, p.amount, NULL, bp.code, bp.name, p.method, p.actor_user_id, s.number
              FROM payments p JOIN sales s ON s.id = p.sale_id LEFT JOIN business_partners bp ON bp.id = p.customer_id
             WHERE p.business_date = :d
            UNION ALL
            SELECT 'CUSTOMER_RETURN', r.returned_at, 'customer_return', r.id, r.number, r.total_value, NULL, bp.code, bp.name, r.refund_method, r.actor_user_id, s.number
              FROM customer_returns r JOIN sales s ON s.id = r.sale_id LEFT JOIN business_partners bp ON bp.id = r.customer_id
             WHERE r.business_date = :d
            UNION ALL
            SELECT 'PAYMENT_REVERSAL', x.reversed_at, 'payment', p.id, p.number, p.amount, NULL, bp.code, bp.name, p.method, x.actor_user_id, x.reason
              FROM payment_reversals x JOIN payments p ON p.id = x.payment_id LEFT JOIN business_partners bp ON bp.id = p.customer_id
             WHERE x.business_date = :d
            UNION ALL
            SELECT 'SALE_REVERSAL', s.reversed_at, 'sale', s.id, s.number, s.grand_total, NULL, bp.code, bp.name, NULL, s.reversed_by, s.status_reason
              FROM sales s LEFT JOIN business_partners bp ON bp.id = s.customer_id
             WHERE s.reversed_at IS NOT NULL AND (s.reversed_at AT TIME ZONE :tz)::date = :d
            UNION ALL
            SELECT 'GOODS_RECEIPT_REVERSAL', g.reversed_at, 'goods_receipt', g.id, g.number,
                   (SELECT sum(line_cost) FROM goods_receipt_lines WHERE goods_receipt_id = g.id), NULL, bp.code, bp.name, NULL, g.reversed_by, g.status_reason
              FROM goods_receipts g JOIN business_partners bp ON bp.id = g.supplier_id
             WHERE g.reversed_at IS NOT NULL AND (g.reversed_at AT TIME ZONE :tz)::date = :d
        SQL;
        $bind = ['d' => $date, 'tz' => $this->tz()];
        $filter = '';
        if ($type !== null) {
            $filter = ' WHERE a.type = :type';
            $bind['type'] = $type;
        }
        $total = (int) $this->db->selectOne("SELECT count(*) AS n FROM ({$sql}) a{$filter}", $bind)->n;
        $rows = $this->db->select("SELECT a.* FROM ({$sql}) a{$filter} ORDER BY a.occurred_at DESC, a.number DESC LIMIT {$page->perPage} OFFSET {$page->offset()}", $bind);

        return new Page(array_map(fn ($r) => [
            'type' => $r->type,
            'occurred_at' => $r->occurred_at === null ? null : (new \DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
            'document' => ['type' => $r->doc_type, 'id' => $r->doc_id, 'number' => $r->number],
            'amount' => $r->amount === null ? null : (string) $r->amount,
            'quantity' => $r->quantity === null ? null : (string) $r->quantity,
            'counterparty' => $r->party_code === null ? null : ['code' => $r->party_code, 'name' => $r->party_name],
            'method' => $r->method,
            'detail' => $r->detail,
            'actor_user_id' => $r->actor_user_id,
        ], $rows), $total, $page);
    }

    public function search(string $term, string $type, PageRequest $page): Page
    {
        $t = mb_strtolower(trim($term));
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t);
        $digits = preg_replace('/\D/', '', $t);
        // Indonesian numbers: 0812… is stored as +62812…
        $phone = $digits === '' ? '-' : '%'.(str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits).'%';
        $bind = ['exact' => $t, 'prefix' => $esc.'%', 'like' => '%'.$esc.'%', 'phone' => $phone];
        $rank = fn (string $col) => "CASE WHEN lower({$col}) = :exact THEN 0 WHEN lower({$col}) LIKE :prefix THEN 1 ELSE 2 END";

        $parts = [];
        if (in_array($type, ['all', 'products'], true)) {
            $parts[] = "SELECT 'product' AS kind, p.id, p.sku AS code, p.name AS label, p.base_uom_code AS subtitle, p.is_active AS active, {$rank('p.sku')} AS rank
                FROM products p WHERE lower(p.sku) LIKE :like OR lower(p.name) LIKE :like";
        }
        if (in_array($type, ['all', 'partners'], true)) {
            $parts[] = "SELECT 'partner' AS kind, b.id, b.code, b.name AS label, concat_ws(' · ', b.phone, (SELECT string_agg(role, ',' ORDER BY role) FROM business_partner_roles WHERE partner_id = b.id)) AS subtitle, b.is_active AS active, {$rank('b.code')} AS rank
                FROM business_partners b WHERE lower(b.code) LIKE :like OR lower(b.name) LIKE :like OR replace(b.phone, '+', '') LIKE :phone";
        }
        if (in_array($type, ['all', 'documents'], true)) {
            foreach (['sales' => 'sale', 'payments' => 'payment', 'customer_returns' => 'customer_return', 'purchase_orders' => 'purchase_order',
                'goods_receipts' => 'goods_receipt', 'inventory_operations' => 'inventory_operation', 'inventory_lots' => 'inventory_lot'] as $table => $kind) {
                $parts[] = "SELECT '{$kind}' AS kind, d.id, d.number AS code, d.number AS label, d.business_date::text AS subtitle, true AS active, {$rank('d.number')} AS rank FROM {$table} d WHERE lower(d.number) LIKE :prefix";
            }
        }
        // PDO cannot reuse a named placeholder across UNION parts: number them per part
        $sqlParts = [];
        $params = [];
        foreach ($parts as $i => $p) {
            $sqlParts[] = preg_replace_callback('/:(exact|prefix|like|phone)\b/', function ($m) use ($i, $bind, &$params) {
                $params["{$m[1]}_{$i}_".count($params)] = $bind[$m[1]];

                return ':'.array_key_last($params);
            }, $p);
        }
        $union = implode("\nUNION ALL\n", $sqlParts);
        $total = (int) $this->db->selectOne("SELECT count(*) AS n FROM ({$union}) x", $params)->n;
        $rows = $this->db->select("SELECT * FROM ({$union}) x ORDER BY rank, kind, code LIMIT {$page->perPage} OFFSET {$page->offset()}", $params);

        return new Page(array_map(fn ($r) => ['type' => $r->kind, 'id' => $r->id, 'code' => $r->code, 'label' => $r->label, 'subtitle' => $r->subtitle, 'active' => (bool) $r->active], $rows), $total, $page);
    }

    public function nodeByCode(string $code): ?array
    {
        $r = $this->db->table('nodes')->where('code', $code)->where('is_active', true)->first(['id', 'code', 'name']);

        return $r === null ? null : ['id' => $r->id, 'code' => $r->code, 'name' => $r->name];
    }

    public function partnerByCode(string $code, bool $activeOnly = true): ?array
    {
        $q = $this->db->table('business_partners')->where('code', $code);
        if ($activeOnly) {
            $q->where('is_active', true);
        }
        $r = $q->first(['id', 'code', 'name']);

        return $r === null ? null : ['id' => $r->id, 'code' => $r->code, 'name' => $r->name];
    }

    public function uomCodes(): array
    {
        return $this->db->table('uoms')->orderBy('code')->pluck('code')->map(fn ($c) => (string) $c)->all();
    }

    public function operatorCount(): int
    {
        return $this->db->table('users')->count();
    }
}
