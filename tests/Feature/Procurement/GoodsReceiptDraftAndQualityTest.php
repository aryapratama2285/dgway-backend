<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

/**
 * F4A — receiving against a PO: draft edit, mismatch details, actual cost vs
 * agreed price, AVAILABLE/QUARANTINE quality split, posted receipt immutable.
 */
final class GoodsReceiptDraftAndQualityTest extends ProcurementTestCase
{
    public function test_receipt_requires_a_receivable_po_and_matching_terms(): void
    {
        $draft = $this->createPo()->json('data');
        $this->createReceipt([$this->poLine($draft, '10')], $draft['id'])->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_RECEIVABLE');

        $po = $this->confirmedPo();
        $otherSupplier = $this->supplier('SUP-LAIN');
        $this->cmd('POST', 'goods-receipts', ['supplier_id' => $otherSupplier, 'node_id' => $this->node, 'purchase_order_id' => $po['id'], 'lines' => [$this->poLine($po, '10')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'GR_PO_MISMATCH')->assertJsonPath('error.details.field', 'supplier_id');
        $otherNode = $this->node('DG-WAY-2');
        $this->cmd('POST', 'goods-receipts', ['supplier_id' => $this->supplier, 'node_id' => $otherNode, 'purchase_order_id' => $po['id'], 'lines' => [$this->poLine($po, '10')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'GR_PO_MISMATCH')->assertJsonPath('error.details.field', 'node_id');
        $wrong = $this->poLine($po, '10');
        $wrong['product_id'] = $this->product('TELUR-LAIN');
        $this->createReceipt([$wrong], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'GR_PO_MISMATCH')->assertJsonPath('error.details.field', 'lines.0.product_id');
        $this->createReceipt([$this->poLine($po, '101')], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT')
            ->assertJsonPath('error.details.open', '100.0000');
        $this->createReceipt([$this->poLine($po, '0')], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_draft_edit_then_post_and_posted_is_immutable(): void
    {
        $po = $this->confirmedPo();
        $gr = $this->createReceipt([$this->poLine($po, '50')], $po['id'])->assertCreated()
            ->assertJsonPath('data.purchase_order.number', $po['number'])->assertJsonPath('data.lines.0.unit_cost', '23500.0000')
            ->assertJsonPath('data.lines.0.lot', null)->json('data');

        $edited = $this->cmd('PATCH', "goods-receipts/{$gr['id']}", ['expected_version' => $gr['version'], 'notes' => 'timbang ulang',
            'lines' => [$this->poLine($po, '48', '23400')]])->assertOk()
            ->assertJsonPath('data.number', $gr['number'])->assertJsonPath('data.lines.0.received_quantity', '48.0000')
            ->assertJsonPath('data.lines.0.unit_cost', '23400.0000')->assertJsonPath('data.total_cost', '1123200.0000')->json('data');
        $this->cmd('PATCH', "goods-receipts/{$gr['id']}", ['expected_version' => $edited['version'], 'supplier_id' => $this->supplier])->assertStatus(422);
        $this->assertSame(0, DB::table('stock_movements')->count(), 'a draft never touches stock');

        $posted = $this->cmd('POST', "goods-receipts/{$gr['id']}/post")->assertOk()->json('data');
        $this->assertNotNull($posted['lines'][0]['lot']['number']);
        $this->cmd('PATCH', "goods-receipts/{$gr['id']}", ['expected_version' => $posted['version'], 'notes' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $gr['id'])->value('id');
        try {
            DB::table('goods_receipt_lines')->where('id', $line)->update(['purchase_unit_cost' => 1, 'unit_cost' => 1, 'line_cost' => 48]);
            $this->fail('a posted receipt line was changed');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23001', $e->errorInfo[0], $e->getMessage());
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_receipt_cost_may_differ_from_po_price_without_touching_it(): void
    {
        $po = $this->confirmedPo();
        $a = $this->receivePo($po, [$this->poLine($po, '60')]);
        $b = $this->receivePo($po, [$this->poLine($po, '30', '23600')]);
        $this->assertSame(['23500.0000', '23600.0000'], [$a['lines'][0]['unit_cost'], $b['lines'][0]['unit_cost']]);
        $this->assertSame('23500.0000', $this->po($po['id'])['lines'][0]['unit_cost'], 'the PO keeps the agreed price');
        $costs = DB::table('stock_movements')->where('movement_type', 'RECEIPT')->orderBy('sequence')->pluck('unit_cost')->map(fn ($c) => (string) $c)->all();
        $this->assertSame(['23500.0000', '23600.0000'], $costs);
    }

    public function test_quality_split_available_and_quarantine(): void
    {
        $po = $this->confirmedPo();
        $gr = $this->receivePo($po, [$this->poLine($po, '100', null, ['dispositions' => [
            ['type' => 'ACCEPT', 'quantity' => '95'], ['type' => 'QUARANTINE', 'quantity' => '5', 'reason' => 'retak halus'],
        ]])]);
        $this->assertSame(['95.0000', '5.0000'], [$this->stock()['available'], $this->stock()['quarantine']]);
        $this->assertSame('5.0000', $gr['yield_analysis'][0]['quarantined']);
        $this->assertSame('RECEIVED', $this->po($po['id'])['status'], 'quarantined goods are received goods');
        $lot = DB::table('inventory_lots')->where('goods_receipt_id', $gr['id'])->sole();
        $this->assertSame(['95.0000', '5.0000'], [
            (string) DB::table('stock_lot_balances')->where('lot_id', $lot->id)->where('bucket', 'AVAILABLE')->value('quantity'),
            (string) DB::table('stock_lot_balances')->where('lot_id', $lot->id)->where('bucket', 'QUARANTINE')->value('quantity'),
        ]);
        $this->get_('dashboard?date=2026-10-03')->assertOk()->assertJsonPath('data.purchasing.received_quantity.0.quarantine', '5.0000');

        // mass balance still holds; release later through the existing RECOVERY operation
        $po2 = $this->confirmedPo();
        $this->createReceipt([$this->poLine($po2, '10', null, ['dispositions' => [['type' => 'ACCEPT', 'quantity' => '9'], ['type' => 'QUARANTINE', 'quantity' => '2']]])], $po2['id'])
            ->assertStatus(422)->assertJsonPath('error.code', 'MASS_BALANCE_VIOLATED');
        $this->cmd('POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '5', 'from_bucket' => 'QUARANTINE', 'reason' => 'lolos cek'])->assertCreated();
        $this->assertSame(['100.0000', '0.0000'], [$this->stock()['available'], $this->stock()['quarantine']]);
        $this->assertLedgerConsistent();
    }
}
