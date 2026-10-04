<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;

final class ReceivingDispositionTest extends InventoryTestCase
{
    public function test_return_to_supplier_is_held_not_lost_then_shipped_back(): void
    {
        $this->receive([$this->line('100', '23000', [
            ['type' => 'ACCEPT', 'quantity' => '92'],
            ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => '8', 'reason' => 'retak'],
        ])]);

        $s = $this->stock();
        $this->assertSame('92.0000', $s['available']);
        $this->assertSame('8.0000', $s['return_pending']);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'LOSS')->count(), 'a return is not a loss');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inventory.return_to_supplier')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inventory.loss')->count());

        $this->cmd('POST', 'inventory/supplier-returns', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '8', 'reason' => 'diambil sopir supplier'])
            ->assertCreated()->assertJsonPath('data.type', 'SUPPLIER_RETURN')->assertJsonPath('data.from_bucket', 'RETURN_PENDING');
        $s = $this->stock();
        $this->assertSame('0.0000', $s['return_pending']);
        $this->assertSame('92.0000', $s['available']);
        // cannot ship more than is pending
        $this->cmd('POST', 'inventory/supplier-returns', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertLedgerConsistent();
    }

    public function test_pending_return_can_be_recovered_into_available(): void
    {
        $this->receive([$this->line('50', '23000', [
            ['type' => 'ACCEPT', 'quantity' => '45'], ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => '5'],
        ])]);
        $this->cmd('POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '3', 'from_bucket' => 'RETURN_PENDING', 'reason' => 'supplier menolak retur, layak jual'])
            ->assertCreated()->assertJsonPath('data.type', 'RECOVERY');
        $s = $this->stock();
        $this->assertSame('48.0000', $s['available']);
        $this->assertSame('2.0000', $s['return_pending']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inventory.recovery')->count());
    }

    public function test_regrade_at_receipt_moves_quantity_to_target_grade_with_one_correlation(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        $id = $this->receive([$this->line('150', '23000', [
            ['type' => 'ACCEPT', 'quantity' => '130'],
            ['type' => 'REGRADE', 'quantity' => '15', 'target_product_id' => $afkir, 'reason' => 'kecil/kotor'],
            ['type' => 'LOSS', 'quantity' => '5', 'reason' => 'pecah'],
        ])]);

        $this->assertSame('130.0000', $this->stock()['available']);
        $this->assertSame('15.0000', $this->stock($afkir)['available']);
        $this->assertSame('0.0000', $this->stock()['quarantine']);

        $out = DB::table('stock_movements')->where('movement_type', 'REGRADE_OUT')->first();
        $in = DB::table('stock_movements')->where('movement_type', 'REGRADE_IN')->first();
        $this->assertSame($out->correlation_id, $in->correlation_id);
        $this->assertSame($id, $out->correlation_id);
        $this->assertSame($this->egg, $out->product_id);
        $this->assertSame($afkir, $in->product_id);
        $this->assertEquals((float) $out->quantity, (float) $in->quantity);

        // mass balance in the ledger: Σ RECEIPT = received
        $this->assertEquals(150.0, (float) DB::table('stock_movements')->where('movement_type', 'RECEIPT')->sum('quantity'));

        $gr = $this->get_("goods-receipts/{$id}")->json('data');
        $y = $gr['yield_analysis'][0];
        $this->assertSame(['150.0000', '130.0000', '5.0000', '15.0000', '0.0000'], [$y['received'], $y['accepted'], $y['loss'], $y['regraded'], $y['returned_to_supplier']]);
        $this->assertSame('3450000.0000', $y['purchase_cost']);
        $this->assertSame('26538.4615', $y['cost_per_accepted_unit']); // raw figure only — no HPP policy applied

        $actions = DB::table('audit_logs')->where('entity_id', $id)->pluck('action')->all();
        foreach (['goods_receipt.created', 'goods_receipt.posted', 'inventory.receipt', 'inventory.regrade', 'inventory.loss'] as $a) {
            $this->assertContains($a, $actions);
        }
        $this->assertLedgerConsistent();
    }

    public function test_mass_balance_and_disposition_validation(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        $ikat = $this->product('SAYUR-IKAT', null, 'IKAT');

        $this->createReceipt([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '9']])])
            ->assertStatus(422)->assertJsonPath('error.code', 'MASS_BALANCE_VIOLATED');
        $this->createReceipt([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '141'], ['type' => 'LOSS', 'quantity' => '10']])])
            ->assertStatus(422)->assertJsonPath('error.code', 'MASS_BALANCE_VIOLATED');
        $this->createReceipt([$this->line('10', '23000', [['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $this->egg]])])
            ->assertStatus(422)->assertJsonPath('error.code', 'REGRADE_TO_SAME_PRODUCT');
        $this->createReceipt([$this->line('10', '23000', [['type' => 'REGRADE', 'quantity' => '10']])])
            ->assertStatus(422);
        $this->createReceipt([$this->line('10', '23000', [['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $ikat]])])
            ->assertStatus(422)->assertJsonPath('error.code', 'UOM_NOT_BASE');
        $this->createReceipt([$this->line('10', '23000', [['type' => 'RECOVERY', 'quantity' => '10']])])
            ->assertStatus(422)->assertJsonPath('error.code', 'DISPOSITION_NOT_FOR_RECEIPT');
        $this->createReceipt([$this->line('10', null)])->assertStatus(422)->assertJsonPath('error.code', 'UNIT_COST_REQUIRED');

        // nothing was created by the rejected requests (and no GR number burned)
        $this->assertSame(0, DB::table('goods_receipts')->count());
        $this->createReceipt([$this->line('10', '23000', [['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $afkir]])])
            ->assertCreated()->assertJsonPath('data.number', 'GR-261003-001');
    }

    public function test_decimal_quantities_and_cost_are_exact(): void
    {
        $id = $this->receive([$this->line('150.125', '23000.50', [['type' => 'ACCEPT', 'quantity' => '149.875'], ['type' => 'LOSS', 'quantity' => '0.25']])]);
        $this->get_("goods-receipts/{$id}")->assertJsonPath('data.lines.0.line_cost', '3452950.0625');
        $this->assertSame('149.8750', $this->stock()['available']);
        $this->createReceipt([$this->line('1.0001', '1')])->assertStatus(422); // finer than KG precision (3 dp)
    }

    public function test_draft_receipt_can_be_cancelled_and_never_posted(): void
    {
        $id = $this->createReceipt([$this->line('10')])->assertCreated()->json('data.id');
        $this->cmd('POST', "goods-receipts/{$id}/cancel", ['reason' => 'salah input'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_receipt_listing_and_movement_trace(): void
    {
        $id = $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])]);
        $this->get_('goods-receipts?status=POSTED')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);

        $m = $this->get_("inventory/movements?source_document_id={$id}")->assertOk()->json();
        $this->assertSame(3, $m['meta']['total']);
        $first = end($m['data']);
        $this->assertSame('RECEIPT', $first['movement_type']);
        $this->assertSame('goods_receipt', $first['source']['type']);
        $this->assertSame('2026-10-03', $first['business_date']);
        $this->assertSame($this->owner->id, $first['actor_user_id']);

        $b = $this->get_('inventory/balances')->assertOk()->json('data');
        $this->assertCount(1, $b); // zero buckets hidden by default
        $this->assertSame('TELUR-STD', $b[0]['sku']);
        $this->assertCount(2, $this->get_('inventory/balances?include_zero=1')->json('data'));
    }
}
