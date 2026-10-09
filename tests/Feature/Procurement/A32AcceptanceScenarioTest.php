<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

/**
 * F4A §27 — the physical acceptance scenario, end to end over the API:
 * PO 100 KG @ Rp23,500 → GR1 60 KG @ 23,500 → GR2 30 KG @ 23,600 → CLOSE (SUPPLIER_SHORTFALL).
 */
final class A32AcceptanceScenarioTest extends ProcurementTestCase
{
    public function test_po_two_partial_receipts_and_close_with_full_traceability(): void
    {
        $po = $this->confirmedPo([['product_id' => $this->egg, 'quantity' => '100', 'unit_cost' => '23500']]);

        $gr1 = $this->receivePo($po, [$this->poLine($po, '60')]);
        $after1 = $this->po($po['id']);
        $this->assertSame(['PARTIALLY_RECEIVED', '60.0000', '40.0000'], [$after1['status'], $after1['lines'][0]['received_quantity'], $after1['lines'][0]['open_quantity']]);
        $this->assertSame('60.0000', $this->stock()['available']);

        $gr2 = $this->receivePo($po, [$this->poLine($po, '30', '23600')]);
        $after2 = $this->po($po['id']);
        $this->assertSame(['PARTIALLY_RECEIVED', '90.0000', '10.0000'], [$after2['status'], $after2['lines'][0]['received_quantity'], $after2['lines'][0]['open_quantity']]);
        $this->assertSame('90.0000', $this->stock()['available']);
        $this->assertNotSame($gr1['number'], $gr2['number']);
        $this->assertNotSame($gr1['lines'][0]['lot']['number'], $gr2['lines'][0]['lot']['number']);

        $closed = $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL'])->assertOk()->json('data');
        $this->assertSame(['CLOSED', '100.0000', '90.0000', '10.0000'],
            [$closed['status'], $closed['lines'][0]['ordered_quantity'], $closed['lines'][0]['received_quantity'], $closed['lines'][0]['unfulfilled_quantity']]);

        // lot → GR line → GR → PO line → PO → supplier, each lot with its own cost
        foreach ([[$gr1, '60.0000', '23500.0000'], [$gr2, '30.0000', '23600.0000']] as [$gr, $qty, $cost]) {
            $lot = $this->get_('lots/'.$gr['lines'][0]['lot']['id'])->assertOk()->json('data');
            $this->assertSame([$gr['number'], 'SUP-BANDUNG', $qty], [$lot['goods_receipt']['number'], $lot['supplier']['code'], $lot['original_quantity']]);
            $chain = DB::table('inventory_lots as l')
                ->join('goods_receipt_lines as gl', 'gl.id', '=', 'l.goods_receipt_line_id')
                ->join('goods_receipts as g', 'g.id', '=', 'gl.goods_receipt_id')
                ->join('purchase_order_lines as pl', 'pl.id', '=', 'gl.purchase_order_line_id')
                ->join('purchase_orders as p', 'p.id', '=', 'pl.purchase_order_id')
                ->where('l.id', $lot['id'])->first(['g.number as gr', 'p.number as po', 'gl.unit_cost', 'pl.unit_cost as agreed', 'p.supplier_id']);
            $this->assertSame([$gr['number'], $po['number'], $cost, '23500.0000', $this->supplier],
                [$chain->gr, $chain->po, (string) $chain->unit_cost, (string) $chain->agreed, $chain->supplier_id]);
            $this->assertSame($cost, (string) DB::table('stock_movements')->where('lot_id', $lot['id'])->where('movement_type', 'RECEIPT')->value('unit_cost'));
        }

        $this->get_("goods-receipts?purchase_order_id={$po['id']}")->assertOk()->assertJsonPath('meta.total', 2);
        $this->get_('search?q='.$gr2['number'])->assertOk()->assertJsonPath('data.0.type', 'goods_receipt');
        $this->get_('activity?date=2026-10-03&type=PURCHASE_ORDER_CLOSED')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertLedgerConsistent();
    }
}
