<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Inventory\LotQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Domain\Shared\Exception\EntityNotFound;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Lot read models. Each method runs a fixed number of SQL statements
 * regardless of page size (joins + one aggregate per page, never one query
 * per row). Remaining quantities come from the stock_lot_balances projection
 * (same transaction as the ledger, verified by dgway:inventory:verify).
 *
 * LEGACY lots own the pre-A5-B movements whose lot_id is NULL; their
 * movement predicate is therefore "lot_id = X OR (lot_id IS NULL AND same
 * product/node)".
 */
final class DbLotQueries implements LotQueries
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function lots(array $filters, PageRequest $page): Page
    {
        $q = $this->baseLots();
        if (($term = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $q->where(fn (Builder $w) => $w
                ->whereRaw('lower(l.number) LIKE ?', [$like])
                ->orWhereRaw('lower(p.sku) LIKE ?', [$like])
                ->orWhereRaw('lower(p.name) LIKE ?', [$like])
                ->orWhereRaw('lower(s.code) LIKE ?', [$like])
                ->orWhereRaw('lower(s.name) LIKE ?', [$like])
                ->orWhereRaw('lower(g.number) LIKE ?', [$like]));
        }
        foreach (['product_id' => 'l.product_id', 'node_id' => 'l.node_id', 'supplier_id' => 'l.supplier_id', 'origin' => 'l.origin', 'goods_receipt_id' => 'l.goods_receipt_id'] as $f => $col) {
            if (! empty($filters[$f])) {
                $q->where($col, $filters[$f]);
            }
        }
        if (! empty($filters['date_from'])) {
            $q->where('l.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('l.business_date', '<=', $filters['date_to']);
        }
        $status = strtoupper((string) ($filters['status'] ?? 'ALL'));
        if ($status === 'ACTIVE') {
            $q->whereRaw('COALESCE(r.on_hand, 0) > 0');
        } elseif ($status === 'DEPLETED') {
            $q->whereRaw('COALESCE(r.on_hand, 0) = 0');
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('l.business_date')->orderByDesc('l.received_at')->orderByDesc('l.number')
            ->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => $this->present($r), $rows), $total, $page);
    }

    public function lot(string $lotIdOrNumber): array
    {
        $q = $this->baseLots()->addSelect('par.number as parent_number');
        $q->leftJoin('inventory_lots as par', 'par.id', '=', 'l.parent_lot_id');
        preg_match(self::UUID, $lotIdOrNumber) === 1 ? $q->where('l.id', $lotIdOrNumber) : $q->where('l.number', $lotIdOrNumber);
        $r = $q->first() ?? throw EntityNotFound::of('InventoryLot', $lotIdOrNumber);

        $lot = $this->present($r);
        $lot['parent'] = $r->parent_lot_id === null ? null : ['id' => $r->parent_lot_id, 'number' => $r->parent_number];
        $lot['children'] = array_map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'product_id' => $c->product_id, 'sku' => $c->sku, 'origin' => $c->origin],
            $this->db->table('inventory_lots as c')->join('products as p', 'p.id', '=', 'c.product_id')
                ->where('c.parent_lot_id', $r->id)->orderBy('c.number')->get(['c.id', 'c.number', 'c.product_id', 'p.sku', 'c.origin'])->all());
        $lot['remaining_by_bucket'] = array_map(fn ($b) => ['bucket' => $b->bucket, 'quantity' => (string) $b->quantity],
            $this->db->table('stock_lot_balances')->where('lot_id', $r->id)->orderBy('bucket')->get(['bucket', 'quantity'])->all());
        $lot['dispositions'] = $r->goods_receipt_line_id === null ? [] : array_map(fn ($d) => [
            'type' => $d->disposition_type, 'quantity' => (string) $d->quantity, 'target_product_id' => $d->target_product_id, 'target_sku' => $d->target_sku, 'reason' => $d->reason,
        ], $this->db->table('goods_receipt_dispositions as d')->leftJoin('products as tp', 'tp.id', '=', 'd.target_product_id')
            ->where('d.goods_receipt_line_id', $r->goods_receipt_line_id)->orderBy('d.created_at')->orderBy('d.id')
            ->get(['d.disposition_type', 'd.quantity', 'd.target_product_id', 'tp.sku as target_sku', 'd.reason'])->all());

        return $lot;
    }

    public function movements(string $lotId, PageRequest $page): Page
    {
        $lot = $this->identity($lotId);
        $q = $this->db->table('stock_movements as m');
        $this->whereLot($q, $lot);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('m.sequence')->offset($page->offset())->limit($page->perPage)
            ->get(['m.id', 'm.sequence', 'm.movement_type', 'm.bucket', 'm.direction', 'm.quantity', 'm.signed_quantity', 'm.uom_code', 'm.business_date', 'm.occurred_at',
                'm.source_document_type', 'm.source_document_id', 'm.source_line_id', 'm.reverses_movement_id', 'm.actor_user_id', 'm.reason', 'm.lot_id'])->all();

        return new Page(array_map(fn ($m) => [
            'id' => $m->id, 'sequence' => (int) $m->sequence, 'movement_type' => $m->movement_type, 'bucket' => $m->bucket,
            'direction' => (int) $m->direction === 1 ? 'IN' : 'OUT', 'quantity' => (string) $m->quantity, 'signed_quantity' => (string) $m->signed_quantity,
            'uom' => $m->uom_code, 'business_date' => (string) $m->business_date, 'occurred_at' => self::atom($m->occurred_at),
            'source' => ['type' => $m->source_document_type, 'id' => $m->source_document_id, 'line_id' => $m->source_line_id],
            'reverses_movement_id' => $m->reverses_movement_id, 'actor_user_id' => $m->actor_user_id, 'reason' => $m->reason,
            'legacy_unattributed' => $m->lot_id === null,
        ], $rows), $total, $page);
    }

    public function lineage(string $lotId): array
    {
        $lot = $this->identity($lotId);
        $row = fn ($r) => ['id' => $r->id, 'number' => $r->number, 'origin' => $r->origin, 'product_id' => $r->product_id, 'sku' => $r->sku,
            'parent_lot_id' => $r->parent_lot_id, 'depth' => (int) $r->depth, 'source' => ['type' => $r->source_document_type, 'id' => $r->source_document_id]];
        $cols = 'l.id, l.number, l.origin, l.product_id, l.parent_lot_id, l.source_document_type, l.source_document_id';

        $ancestors = $this->db->select(<<<SQL
            WITH RECURSIVE up AS (
                SELECT {$cols}, 0 AS depth FROM inventory_lots l WHERE l.id = ?
                UNION ALL
                SELECT {$cols}, up.depth - 1 FROM inventory_lots l JOIN up ON l.id = up.parent_lot_id
            )
            SELECT up.*, p.sku FROM up JOIN products p ON p.id = up.product_id WHERE up.depth < 0 ORDER BY up.depth
        SQL, [$lot->id]);
        $descendants = $this->db->select(<<<SQL
            WITH RECURSIVE down AS (
                SELECT {$cols}, 0 AS depth FROM inventory_lots l WHERE l.id = ?
                UNION ALL
                SELECT {$cols}, down.depth + 1 FROM inventory_lots l JOIN down ON l.parent_lot_id = down.id
            )
            SELECT down.*, p.sku FROM down JOIN products p ON p.id = down.product_id WHERE down.depth > 0 ORDER BY down.depth, down.number
        SQL, [$lot->id]);

        return ['lot_id' => $lot->id, 'lot_number' => $lot->number, 'ancestors' => array_map($row, $ancestors), 'descendants' => array_map($row, $descendants)];
    }

    public function destinations(string $lotId, PageRequest $page): Page
    {
        $lot = $this->identity($lotId);
        $legacy = $lot->origin === 'LEGACY';
        $lotPredicate = $legacy ? '(m.lot_id = :lot OR (m.lot_id IS NULL AND m.product_id = :product AND m.node_id = :node))' : 'm.lot_id = :lot';
        $bind = $legacy ? ['lot' => $lot->id, 'product' => $lot->product_id, 'node' => $lot->node_id] : ['lot' => $lot->id];

        $base = <<<SQL
            WITH sold AS (
                SELECT m.source_line_id AS sale_line_id, min(m.sequence) AS first_seq, sum(m.quantity) AS sold,
                       COALESCE(sum(m.quantity) FILTER (WHERE EXISTS (SELECT 1 FROM stock_movements r WHERE r.reverses_movement_id = m.id)), 0) AS reversed
                  FROM stock_movements m
                 WHERE {$lotPredicate} AND m.movement_type = 'SALE' AND m.source_document_type = 'sale'
                 GROUP BY m.source_line_id
            ), returned AS (
                SELECT crl.sale_line_id, sum(m.quantity) AS returned
                  FROM stock_movements m JOIN customer_return_lines crl ON crl.id = m.source_line_id
                 WHERE {$lotPredicate} AND m.movement_type = 'CUSTOMER_RETURN' AND m.source_document_type = 'customer_return'
                 GROUP BY crl.sale_line_id
            )
        SQL;
        $total = (int) $this->db->selectOne("{$base} SELECT count(*) AS n FROM sold", $bind)->n;
        $rows = $this->db->select("{$base}
            SELECT s.id AS sale_id, s.number AS sale_number, s.business_date, s.status AS sale_status, s.customer_id,
                   c.code AS customer_code, c.name AS customer_name, c.phone AS customer_phone, sl.id AS sale_line_id, sl.line_no,
                   so.sold::numeric(18,4) AS sold, so.reversed::numeric(18,4) AS reversed, COALESCE(rt.returned, 0)::numeric(18,4) AS returned,
                   (so.sold - so.reversed - COALESCE(rt.returned, 0))::numeric(18,4) AS net, sl.uom_code
              FROM sold so
              JOIN sale_lines sl ON sl.id = so.sale_line_id
              JOIN sales s ON s.id = sl.sale_id
              LEFT JOIN business_partners c ON c.id = s.customer_id
              LEFT JOIN returned rt ON rt.sale_line_id = so.sale_line_id
             ORDER BY so.first_seq
             LIMIT {$page->perPage} OFFSET {$page->offset()}", $bind);

        return new Page(array_map(fn ($r) => [
            'sale_id' => $r->sale_id, 'sale_number' => $r->sale_number, 'business_date' => (string) $r->business_date, 'sale_status' => $r->sale_status,
            'sale_line_id' => $r->sale_line_id, 'line_no' => (int) $r->line_no,
            'customer' => $r->customer_id === null ? null : ['id' => $r->customer_id, 'code' => $r->customer_code, 'name' => $r->customer_name, 'phone' => $r->customer_phone],
            'sold' => (string) $r->sold, 'reversed' => (string) $r->reversed, 'returned' => (string) $r->returned, 'net' => (string) $r->net, 'uom' => $r->uom_code,
        ], $rows), $total, $page);
    }

    public function balances(?string $nodeId, ?string $productId, ?string $bucket, bool $includeZero, PageRequest $page): Page
    {
        $q = $this->db->table('stock_lot_balances as b')
            ->join('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('nodes as n', 'n.id', '=', 'b.node_id')
            ->leftJoin('business_partners as s', 's.id', '=', 'l.supplier_id')
            ->select('b.lot_id', 'l.number', 'l.origin', 'l.business_date', 'l.received_at', 'l.best_before_date', 'b.product_id', 'p.sku', 'p.name as product_name',
                'b.node_id', 'n.code as node_code', 'b.bucket', 'b.quantity', 'b.uom_code', 'l.supplier_id', 's.code as supplier_code', 's.name as supplier_name');
        foreach (['b.node_id' => $nodeId, 'b.product_id' => $productId, 'b.bucket' => $bucket] as $col => $v) {
            if ($v !== null) {
                $q->where($col, $v);
            }
        }
        if (! $includeZero) {
            $q->where('b.quantity', '<>', 0);
        }
        $total = (clone $q)->count();
        // FIFO order within a position: the order a sale would consume them.
        $rows = $q->orderBy('p.sku')->orderBy('n.code')->orderBy('l.business_date')->orderBy('l.received_at')->orderBy('l.number')->orderBy('b.bucket')
            ->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'lot_id' => $r->lot_id, 'lot_number' => $r->number, 'origin' => $r->origin, 'business_date' => (string) $r->business_date,
            'received_at' => self::atom($r->received_at), 'best_before_date' => $r->best_before_date,
            'product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'node_id' => $r->node_id, 'node_code' => $r->node_code,
            'supplier' => $r->supplier_id === null ? null : ['id' => $r->supplier_id, 'code' => $r->supplier_code, 'name' => $r->supplier_name],
            'bucket' => $r->bucket, 'quantity' => (string) $r->quantity, 'uom' => $r->uom_code,
        ], $rows), $total, $page);
    }

    /** Lots joined with product, node, supplier, receipt and the per-lot remaining aggregate (one LATERAL per returned row). */
    private function baseLots(): Builder
    {
        return $this->db->table('inventory_lots as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('nodes as n', 'n.id', '=', 'l.node_id')
            ->leftJoin('business_partners as s', 's.id', '=', 'l.supplier_id')
            ->leftJoin('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->leftJoinLateral($this->db->table('stock_lot_balances as b')->whereColumn('b.lot_id', 'l.id')->selectRaw(<<<'SQL'
                sum(quantity)::numeric(18,4) AS on_hand,
                COALESCE(sum(quantity) FILTER (WHERE bucket = 'AVAILABLE'), 0)::numeric(18,4) AS available,
                COALESCE(sum(quantity) FILTER (WHERE bucket = 'QUARANTINE'), 0)::numeric(18,4) AS quarantine,
                COALESCE(sum(quantity) FILTER (WHERE bucket = 'RETURN_PENDING'), 0)::numeric(18,4) AS return_pending
            SQL), 'r')
            ->select('l.*', 'p.sku', 'p.name as product_name', 'n.code as node_code', 's.code as supplier_code', 's.name as supplier_name',
                'g.number as goods_receipt_number', $this->db->raw("CASE WHEN COALESCE(r.on_hand, 0) > 0 THEN 'ACTIVE' ELSE 'DEPLETED' END AS derived_status"), 'r.on_hand', 'r.available', 'r.quarantine', 'r.return_pending');
    }

    private function present(object $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'origin' => $r->origin,
            'status' => $r->derived_status,
            'product' => ['id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->product_name],
            'node' => ['id' => $r->node_id, 'code' => $r->node_code],
            'uom' => $r->uom_code,
            'original_quantity' => $r->original_quantity === null ? null : (string) $r->original_quantity,
            'remaining' => [
                'on_hand' => self::q($r->on_hand), 'available' => self::q($r->available),
                'quarantine' => self::q($r->quarantine), 'return_pending' => self::q($r->return_pending),
            ],
            'supplier' => $r->supplier_id === null ? null : ['id' => $r->supplier_id, 'code' => $r->supplier_code, 'name' => $r->supplier_name],
            'goods_receipt' => $r->goods_receipt_id === null ? null : ['id' => $r->goods_receipt_id, 'number' => $r->goods_receipt_number, 'line_id' => $r->goods_receipt_line_id],
            'parent_lot_id' => $r->parent_lot_id,
            'source' => ['type' => $r->source_document_type, 'id' => $r->source_document_id],
            'business_date' => (string) $r->business_date,
            'received_at' => self::atom($r->received_at),
            'manufactured_date' => $r->manufactured_date,
            'best_before_date' => $r->best_before_date,
            // A5-C: upstream facts as recorded from the supplier; null = unknown (never the received date)
            'collection_date' => $r->collection_date ?? null,
            'supplier_reference' => $r->supplier_reference ?? null,
            'provenance' => $r->origin === 'LEGACY' ? 'UNATTRIBUTED_LEGACY' : 'RECORDED',
            'created_at' => self::atom($r->created_at),
        ];
    }

    private function identity(string $lotId): object
    {
        $q = $this->db->table('inventory_lots');
        preg_match(self::UUID, $lotId) === 1 ? $q->where('id', $lotId) : $q->where('number', $lotId);

        return $q->first(['id', 'number', 'origin', 'product_id', 'node_id']) ?? throw EntityNotFound::of('InventoryLot', $lotId);
    }

    private function whereLot(Builder $q, object $lot): void
    {
        if ($lot->origin !== 'LEGACY') {
            $q->where('m.lot_id', $lot->id);

            return;
        }
        $q->where(fn (Builder $w) => $w->where('m.lot_id', $lot->id)
            ->orWhere(fn (Builder $x) => $x->whereNull('m.lot_id')->where('m.product_id', $lot->product_id)->where('m.node_id', $lot->node_id)));
    }

    private static function q(mixed $v): string
    {
        return $v === null ? '0.0000' : (string) $v;
    }

    private static function atom(mixed $ts): ?string
    {
        return $ts === null ? null : (new \DateTimeImmutable((string) $ts))->format(DATE_ATOM);
    }
}
