<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Procurement\ProcurementQueries;
use App\Application\Procurement\ProcurementReferences;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\PurchaseOrder;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Infrastructure\Persistence\Repository\DbGoodsReceiptRepository;
use App\Infrastructure\Persistence\Repository\DbPurchaseOrderRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class DbProcurementQueries implements ProcurementQueries
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DbPurchaseOrderRepository $orders,
        private readonly DbGoodsReceiptRepository $receipts,
    ) {
    }

    public function purchaseOrders(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('purchase_orders');
        $this->filter($q, $filters, ['status', 'supplier_id', 'node_id']);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('business_date')->orderByDesc('number')->offset($page->offset())->limit($page->perPage)->get()->all();

        $lines = $rows === [] ? collect() : $this->db->table('purchase_order_lines')
            ->whereIn('purchase_order_id', array_map(fn ($r) => $r->id, $rows))->orderBy('line_no')->get()->groupBy('purchase_order_id');

        return new Page(array_map(fn ($r) => $this->orders->hydrate($r, $lines->get($r->id)?->all() ?? []), $rows), $total, $page);
    }

    public function goodsReceipts(array $filters, PageRequest $page): Page
    {
        $q = $this->db->table('goods_receipts');
        $this->filter($q, $filters, ['status', 'supplier_id', 'node_id', 'purchase_order_id']);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('business_date')->orderByDesc('number')->offset($page->offset())->limit($page->perPage)->get()->all();

        [$lines, $dispositions] = $this->receipts->detailRows(array_map(fn ($r) => $r->id, $rows));
        $byReceipt = collect($lines)->groupBy('goods_receipt_id');

        return new Page(array_map(fn ($r) => $this->receipts->hydrate($r, $byReceipt->get($r->id)?->all() ?? [], $dispositions), $rows), $total, $page);
    }

    public function references(array $documents): ProcurementReferences
    {
        $partners = $nodes = $products = $orders = $receiptLines = $orderIds = [];
        foreach ($documents as $d) {
            $partners[$d->supplierId->value] = true;
            $nodes[$d->nodeId->value] = true;
            if ($d instanceof PurchaseOrder) {
                $orderIds[$d->id()->value] = true;
                foreach ($d->lines() as $l) {
                    $products[$l->productId->value] = true;
                }
            }
            if ($d instanceof GoodsReceipt) {
                if ($d->purchaseOrderId !== null) {
                    $orders[$d->purchaseOrderId->value] = true;
                }
                foreach ($d->lines() as $l) {
                    $products[$l->productId->value] = true;
                    $receiptLines[$l->id->value] = true;
                    foreach ($l->dispositions as $disp) {
                        if ($disp->targetProductId !== null) {
                            $products[$disp->targetProductId->value] = true;
                        }
                    }
                }
            }
        }
        $by = fn (array $rows, string $key = 'id') => array_column(array_map(fn ($r) => (array) $r, $rows), null, $key);

        return new ProcurementReferences(
            $partners === [] ? [] : $by($this->db->table('business_partners')->whereIn('id', array_keys($partners))->get(['id', 'code', 'name'])->all()),
            $nodes === [] ? [] : $by($this->db->table('nodes')->whereIn('id', array_keys($nodes))->get(['id', 'code', 'name'])->all()),
            $products === [] ? [] : $by($this->db->table('products')->whereIn('id', array_keys($products))
                ->get(['id', 'sku', 'name', 'classification', 'base_uom_code as base_uom'])->all()),
            $orders === [] ? [] : $by($this->db->table('purchase_orders')->whereIn('id', array_keys($orders))->get(['id', 'number', 'status'])->all()),
            $receiptLines === [] ? [] : array_map(fn ($r) => ['id' => $r['id'], 'number' => $r['number']],
                $by($this->db->table('inventory_lots')->where('origin', 'RECEIPT')->whereIn('goods_receipt_line_id', array_keys($receiptLines))
                    ->get(['id', 'number', 'goods_receipt_line_id'])->all(), 'goods_receipt_line_id')),
            $orderIds === [] ? [] : array_map(fn ($r) => ['count' => (int) $r['count'], 'posted' => (int) $r['posted']],
                $by($this->db->table('goods_receipts')->whereIn('purchase_order_id', array_keys($orderIds))->groupBy('purchase_order_id')
                    ->get(['purchase_order_id', $this->db->raw('count(*) AS count'), $this->db->raw("count(*) FILTER (WHERE status = 'POSTED') AS posted")])->all(), 'purchase_order_id')),
        );
    }

    public function uomConversions(string $productId): array
    {
        return array_map(fn ($r) => [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'uom' => $r->uom_code,
            'base_uom' => $r->base_uom_code,
            'factor_to_base' => (string) $r->factor_to_base,
            'is_active' => (bool) $r->is_active,
            'version' => (int) $r->version,
            'updated_at' => (new \DateTimeImmutable((string) $r->updated_at))->format(DATE_ATOM),
        ], $this->db->table('product_uom_conversions as c')->join('products as p', 'p.id', '=', 'c.product_id')
            ->where('c.product_id', $productId)->orderBy('c.uom_code')
            ->get(['c.id', 'c.product_id', 'c.uom_code', 'p.base_uom_code', 'c.factor_to_base', 'c.is_active', 'c.version', 'c.updated_at'])->all());
    }

    /** @param list<string> $equals */
    private function filter(Builder $q, array $filters, array $equals): void
    {
        foreach ($equals as $f) {
            if ($f === 'status' && strtoupper((string) ($filters[$f] ?? '')) === 'OPEN') {
                // F4A: purchase orders waiting for goods
                $q->whereIn('status', ['CONFIRMED', 'PARTIALLY_RECEIVED']);

                continue;
            }
            if (! empty($filters[$f])) {
                $q->where($f, $f === 'status' ? strtoupper((string) $filters[$f]) : $filters[$f]);
            }
        }
        if (! empty($filters['date_from'])) {
            $q->where('business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('business_date', '<=', $filters['date_to']);
        }
    }
}
