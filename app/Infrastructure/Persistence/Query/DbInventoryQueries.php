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
            ->select('m.*', 'p.sku');
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
            'source' => ['type' => $r->source_document_type, 'id' => $r->source_document_id, 'line_id' => $r->source_line_id],
            'correlation_id' => $r->correlation_id,
            'reverses_movement_id' => $r->reverses_movement_id,
            'actor_user_id' => $r->actor_user_id,
            'reason' => $r->reason,
        ], $rows), $total, $page);
    }
}
