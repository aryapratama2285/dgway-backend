<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

abstract class LotTestCase extends SalesTestCase
{
    /** Posts a direct-purchase receipt on a given business date; returns [receiptId, lotId]. */
    protected function receiveOn(string $date, string $qty, ?array $dispositions = null, ?string $product = null, array $lineExtra = []): array
    {
        $id = $this->cmd('POST', 'goods-receipts', [
            'supplier_id' => $this->supplier, 'node_id' => $this->node, 'business_date' => $date,
            'lines' => [array_merge($this->line($qty, '23000', $dispositions ?? [], $product), $lineExtra)],
        ])->assertCreated()->json('data.id');
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk();

        return [$id, (string) DB::table('inventory_lots')->where('goods_receipt_id', $id)->where('origin', 'RECEIPT')->value('id')];
    }

    /** Remaining quantity of a lot in a bucket, from the ledger (legacy-aware). */
    protected function lotQty(string $lotId, string $bucket = 'AVAILABLE'): string
    {
        return (string) DB::selectOne("SELECT COALESCE(sum(signed_quantity), 0)::numeric(18,4) AS q FROM stock_movements
            WHERE COALESCE(lot_id, dgway_legacy_lot(product_id, node_id)) = ? AND bucket = ?", [$lotId, $bucket])->q;
    }

    /** @return list<array{lot_id: string, qty: string}> SALE movements of a sale, in order */
    protected function saleAllocation(string $saleId): array
    {
        return array_map(fn ($r) => ['lot_id' => $r->lot_id, 'qty' => (string) $r->quantity], DB::select(
            "SELECT lot_id, quantity FROM stock_movements WHERE source_document_id = ? AND movement_type = 'SALE' ORDER BY sequence", [$saleId]));
    }
}
