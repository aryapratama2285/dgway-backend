<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Inventory\InventoryQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Domain\Shared\Exception\EntityNotFound;
use Illuminate\Database\ConnectionInterface;

final class DbInventoryQueries implements InventoryQueries
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function currentStock(string $productId, string $nodeId): array
    {
        $product = $this->db->table('products')->where('id', $productId)->first(['id', 'base_uom_code']) ?? throw EntityNotFound::of('Product', $productId);
        $this->db->table('nodes')->where('id', $nodeId)->exists() || throw EntityNotFound::of('Node', $nodeId);

        $byBucket = $this->db->table('stock_balances')->where('product_id', $productId)->where('node_id', $nodeId)
            ->pluck('quantity', 'bucket')->all();
        $ledger = (string) ($this->db->table('stock_movements')->where('product_id', $productId)->where('node_id', $nodeId)
            ->selectRaw('COALESCE(sum(signed_quantity), 0)::numeric(18,4) AS q')->value('q'));

        $q = fn (string $b) => (string) ($byBucket[$b] ?? '0.0000');
        $onHand = (string) $this->db->selectOne('SELECT (?::numeric + ?::numeric + ?::numeric)::numeric(18,4) AS s', [$q('AVAILABLE'), $q('QUARANTINE'), $q('RETURN_PENDING')])->s;

        return [
            'product_id' => $productId,
            'node_id' => $nodeId,
            'uom' => $product->base_uom_code,
            'available' => $q('AVAILABLE'),
            'quarantine' => $q('QUARANTINE'),
            'return_pending' => $q('RETURN_PENDING'),
            'on_hand' => $onHand,
            'ledger_on_hand' => $ledger,
        ];
    }

    public function balances(?string $nodeId, ?string $productId, ?string $bucket, bool $includeZero, PageRequest $page): Page
    {
        $q = $this->db->table('stock_balances as b')
            ->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('nodes as n', 'n.id', '=', 'b.node_id')
            ->select('b.product_id', 'p.sku', 'p.name as product_name', 'b.node_id', 'n.code as node_code', 'b.bucket', 'b.quantity', 'b.uom_code', 'b.updated_at')
            ->orderBy('p.sku')->orderBy('n.code')->orderBy('b.bucket');
        if ($nodeId !== null) {
            $q->where('b.node_id', $nodeId);
        }
        if ($productId !== null) {
            $q->where('b.product_id', $productId);
        }
        if ($bucket !== null) {
            $q->where('b.bucket', $bucket);
        }
        if (! $includeZero) {
            $q->where('b.quantity', '<>', 0);
        }

        $total = (clone $q)->count();
        $rows = $q->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name,
            'node_id' => $r->node_id, 'node_code' => $r->node_code, 'bucket' => $r->bucket,
            'quantity' => (string) $r->quantity, 'uom' => $r->uom_code,
        ], $rows), $total, $page);
    }

    public function movements(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'm.lot_id')
            ->leftJoin('inventory_operations as io', fn ($j) => $j->on('io.id', '=', 'm.source_document_id')->where('m.source_document_type', '=', 'inventory_operation'))
            ->leftJoin('goods_receipts as g', fn ($j) => $j->on('g.id', '=', 'm.source_document_id')->where('m.source_document_type', '=', 'goods_receipt'))
            ->leftJoin('sales as s', fn ($j) => $j->on('s.id', '=', 'm.source_document_id')->where('m.source_document_type', '=', 'sale'))
            ->leftJoin('customer_returns as cr', fn ($j) => $j->on('cr.id', '=', 'm.source_document_id')->where('m.source_document_type', '=', 'customer_return'))
            ->select('m.*', 'p.sku', 'p.name as product_name', 'p.classification', 'l.number as lot_number', 'io.reason_code',
                $this->db->raw('COALESCE(io.number, g.number, s.number, cr.number) AS source_number'));
        foreach (['product_id', 'node_id', 'bucket', 'movement_type', 'source_document_id', 'correlation_id'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("m.{$f}", $filters[$f]);
            }
        }
        if (! empty($filters['date_from'])) {
            $q->where('m.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('m.business_date', '<=', $filters['date_to']);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('m.sequence')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'id' => $r->id,
            'sequence' => (int) $r->sequence,
            'movement_type' => $r->movement_type,
            'product_id' => $r->product_id,
            'sku' => $r->sku,
            'product_name' => $r->product_name,
            'classification' => $r->classification,
            'node_id' => $r->node_id,
            'bucket' => $r->bucket,
            'direction' => (int) $r->direction === 1 ? 'IN' : 'OUT',
            'quantity' => (string) $r->quantity,
            'signed_quantity' => (string) $r->signed_quantity,
            'uom' => $r->uom_code,
            'unit_cost' => $r->unit_cost === null ? null : (string) $r->unit_cost,
            'currency' => $r->currency,
            'business_date' => (string) $r->business_date,
            'occurred_at' => (new \DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
            'source' => ['type' => $r->source_document_type, 'id' => $r->source_document_id, 'line_id' => $r->source_line_id, 'number' => $r->source_number],
            'lot' => $r->lot_id === null ? null : ['id' => $r->lot_id, 'number' => $r->lot_number],
            'correlation_id' => $r->correlation_id,
            'reverses_movement_id' => $r->reverses_movement_id,
            'actor_user_id' => $r->actor_user_id,
            'reason' => $r->reason,
            'reason_code' => $r->reason_code,
        ], $rows), $total, $page);
    }

    public function stock(array $filters, PageRequest $page): Page
    {
        $available = 'COALESCE(b.available, 0)';
        $low = self::lowStockCondition($available);
        $q = $this->db->table('products as p')
            ->join('uoms as u', 'u.code', '=', 'p.base_uom_code')
            ->crossJoin('nodes as n')
            ->leftJoinSub($this->db->table('stock_balances')->select('product_id', 'node_id')
                ->selectRaw("sum(quantity) FILTER (WHERE bucket = 'AVAILABLE') AS available")
                ->selectRaw("sum(quantity) FILTER (WHERE bucket = 'QUARANTINE') AS quarantine")
                ->selectRaw("sum(quantity) FILTER (WHERE bucket = 'RETURN_PENDING') AS return_pending")
                ->groupBy('product_id', 'node_id'), 'b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->on('b.node_id', '=', 'n.id'))
            ->leftJoin('reorder_points as rp', fn ($j) => $j->on('rp.product_id', '=', 'p.id')->on('rp.node_id', '=', 'n.id'))
            // stock-holding nodes; a node that lost the capability still shows the stock it holds
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('n.is_active', true)->whereRaw("'INVENTORY' = ANY(n.capabilities)"))->orWhereNotNull('b.product_id'));
        if (! empty($filters['node_id'])) {
            $q->where('n.id', $filters['node_id']);
        }
        if (! empty($filters['product_id'])) {
            $q->where('p.id', $filters['product_id']);
        }
        if (! empty($filters['classification'])) {
            $q->where('p.classification', $filters['classification']);
        }
        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = '%'.DbCatalogQueries::escapeLike(mb_strtolower(trim((string) $filters['q']))).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(p.name) LIKE ?', [$term])->orWhereRaw('lower(p.sku) LIKE ?', [$term]));
        }
        if (! empty($filters['low_stock'])) {
            $q->whereRaw($low);
        }
        if (empty($filters['include_zero']) && empty($filters['product_id'])) {
            // default list: positions that hold something, or that are below their threshold
            $q->whereRaw("(COALESCE(b.available, 0) <> 0 OR COALESCE(b.quarantine, 0) <> 0 OR COALESCE(b.return_pending, 0) <> 0 OR {$low})");
        } else {
            // include_zero lists products without stock too, but only active ones (inactive ones only while they still hold stock)
            $q->where(fn ($w) => $w->where('p.is_active', true)->orWhereNotNull('b.product_id'));
        }
        $total = (clone $q)->count();
        $rows = $q->select('p.id as product_id', 'p.sku', 'p.name', 'p.classification', 'p.is_active', 'p.base_uom_code', 'u.precision',
            'n.id as node_id', 'n.code as node_code', 'n.name as node_name', 'rp.min_quantity')
            ->selectRaw("{$available}::numeric(18,4) AS available")
            ->selectRaw('COALESCE(b.quarantine, 0)::numeric(18,4) AS quarantine')
            ->selectRaw('COALESCE(b.return_pending, 0)::numeric(18,4) AS return_pending')
            ->selectRaw('(COALESCE(b.available, 0) + COALESCE(b.quarantine, 0) + COALESCE(b.return_pending, 0))::numeric(18,4) AS on_hand')
            ->selectRaw("{$low} AS is_low_stock")
            ->orderBy('p.name')->orderBy('p.sku')->orderBy('n.code')
            ->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => [
            'product' => ['id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'classification' => $r->classification, 'is_active' => (bool) $r->is_active],
            'node' => ['id' => $r->node_id, 'code' => $r->node_code, 'name' => $r->node_name],
            'uom' => ['code' => $r->base_uom_code, 'precision' => (int) $r->precision],
            'available' => (string) $r->available,
            'quarantine' => (string) $r->quarantine,
            'return_pending' => (string) $r->return_pending,
            'on_hand' => (string) $r->on_hand,
            'reorder_point' => $r->min_quantity === null ? null : (string) $r->min_quantity,
            'is_low_stock' => (bool) $r->is_low_stock,
        ], $rows), $total, $page);
    }

    public function operations(array $filters, PageRequest $page): Page
    {
        $q = $this->operationQuery();
        foreach (['product_id', 'node_id', 'reason_code'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("o.{$f}", $filters[$f]);
            }
        }
        if (! empty($filters['type'])) {
            $q->where('o.operation_type', $filters['type']);
        }
        if (! empty($filters['date_from'])) {
            $q->where('o.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('o.business_date', '<=', $filters['date_to']);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('o.created_at')->orderByDesc('o.number')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map(fn ($r) => self::operationRow($r), $rows), $total, $page);
    }

    public function operation(string $id): array
    {
        $r = $this->operationQuery()->where('o.id', $id)->first() ?? throw EntityNotFound::of('InventoryOperation', $id);
        $movements = $this->db->table('stock_movements as m')->leftJoin('inventory_lots as l', 'l.id', '=', 'm.lot_id')
            ->where('m.source_document_type', 'inventory_operation')->where('m.source_document_id', $id)
            ->orderBy('m.sequence')
            ->get(['m.id', 'm.movement_type', 'm.product_id', 'm.bucket', 'm.direction', 'm.quantity', 'm.uom_code', 'm.lot_id', 'l.number as lot_number', 'l.origin as lot_origin'])
            ->all();

        return self::operationRow($r) + ['movements' => array_map(fn ($m) => [
            'id' => $m->id,
            'movement_type' => $m->movement_type,
            'product_id' => $m->product_id,
            'bucket' => $m->bucket,
            'direction' => (int) $m->direction === 1 ? 'IN' : 'OUT',
            'quantity' => (string) $m->quantity,
            'uom' => $m->uom_code,
            'lot' => $m->lot_id === null ? null : ['id' => $m->lot_id, 'number' => $m->lot_number, 'origin' => $m->lot_origin],
        ], $movements)];
    }

    public function reorderPoints(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('reorder_points as rp')->join('products as p', 'p.id', '=', 'rp.product_id');
        foreach (['product_id', 'node_id'] as $f) {
            if (! empty($filters[$f])) {
                $q->where("rp.{$f}", $filters[$f]);
            }
        }
        $total = (clone $q)->count();
        $rows = $q->orderBy('p.name')->orderBy('rp.node_id')->offset($page->offset())->limit($page->perPage)
            ->get(['rp.*', 'p.base_uom_code'])->all();

        return new Page(array_map(fn ($r) => [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'node_id' => $r->node_id,
            'min_quantity' => $r->min_quantity === null ? null : (string) $r->min_quantity,
            'uom' => $r->base_uom_code,
            'version' => (int) $r->version,
            'updated_by' => $r->updated_by,
            'updated_at' => (new \DateTimeImmutable((string) $r->updated_at))->format(DATE_ATOM),
        ], $rows), $total, $page);
    }

    /**
     * THE low-stock rule (one definition for the stock list and the dashboard):
     * a threshold is set (null = monitoring disabled), the product is active, the
     * node is an active stock-holding node, and AVAILABLE <= min_quantity.
     * Aliases: p = products, n = nodes, rp = reorder_points.
     */
    public static function lowStockCondition(string $availableExpression): string
    {
        return "(rp.min_quantity IS NOT NULL AND p.is_active AND n.is_active AND 'INVENTORY' = ANY(n.capabilities) AND {$availableExpression} <= rp.min_quantity)";
    }

    private function operationQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('inventory_operations as o')
            ->join('products as p', 'p.id', '=', 'o.product_id')
            ->join('nodes as n', 'n.id', '=', 'o.node_id')
            ->select('o.*', 'p.sku', 'p.name as product_name', 'p.classification', 'n.code as node_code', 'n.name as node_name');
    }

    /** @return array<string, mixed> */
    private static function operationRow(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (string) $v;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'type' => $r->operation_type,
            'node_id' => $r->node_id,
            'product_id' => $r->product_id,
            'target_product_id' => $r->target_product_id,
            'quantity' => (string) $r->quantity,
            'uom' => $r->uom_code,
            'from_bucket' => $r->from_bucket,
            'direction' => $r->adjustment_direction === null ? null : ((int) $r->adjustment_direction === 1 ? 'IN' : 'OUT'),
            'reason' => $r->reason,
            'reason_code' => $r->reason_code,
            'reference_text' => $r->reference_text,
            'counted_quantity' => $n($r->counted_quantity),
            'system_quantity' => $n($r->system_quantity),
            'business_date' => (string) $r->business_date,
            'occurred_at' => (new \DateTimeImmutable((string) $r->occurred_at))->format(DATE_ATOM),
            'product' => ['id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->product_name, 'classification' => $r->classification],
            'node' => ['id' => $r->node_id, 'code' => $r->node_code, 'name' => $r->node_name],
            'actor_user_id' => $r->actor_user_id,
            'created_at' => (new \DateTimeImmutable((string) $r->created_at))->format(DATE_ATOM),
        ];
    }
}
