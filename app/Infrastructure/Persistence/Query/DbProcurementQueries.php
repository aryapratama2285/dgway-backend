<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Procurement\ProcurementQueries;
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

    /** @param list<string> $equals */
    private function filter(Builder $q, array $filters, array $equals): void
    {
        foreach ($equals as $f) {
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
