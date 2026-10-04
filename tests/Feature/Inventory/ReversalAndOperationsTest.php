<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;

final class ReversalAndOperationsTest extends InventoryTestCase
{
    private function scenarioA(): string
    {
        return $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10', 'reason' => 'pecah']])]);
    }

    public function test_reversal_compensates_every_movement_without_touching_history(): void
    {
        $id = $this->scenarioA();
        $before = DB::table('stock_movements')->orderBy('sequence')->get()->map(fn ($r) => (array) $r)->all();

        $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'salah supplier'])->assertOk()
            ->assertJsonPath('data.status', 'REVERSED')->assertJsonPath('data.status_reason', 'salah supplier');

        $s = $this->stock();
        $this->assertSame('0.0000', $s['available']);
        $this->assertSame('0.0000', $s['ledger_on_hand']);

        // originals are untouched, 3 compensating REVERSAL rows appended
        $after = DB::table('stock_movements')->orderBy('sequence')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertSame($before, array_slice($after, 0, 3));
        $reversals = array_slice($after, 3);
        $this->assertCount(3, $reversals);
        foreach ($reversals as $r) {
            $this->assertSame('REVERSAL', $r['movement_type']);
            $orig = collect($before)->firstWhere('id', $r['reverses_movement_id']);
            $this->assertSame(-1 * (int) $orig['direction'], (int) $r['direction']);
            $this->assertSame($orig['quantity'], $r['quantity']);
            $this->assertSame($orig['bucket'], $r['bucket']);
        }
        $this->assertCount(1, array_unique(array_column($reversals, 'correlation_id')));
        $this->assertNotSame($id, $reversals[0]['correlation_id']);
        // newest-first: the LOSS (last original) is compensated first
        $this->assertSame($before[2]['id'], $reversals[0]['reverses_movement_id']);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'goods_receipt.reversed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inventory.reversal')->count());
        $this->assertLedgerConsistent();
    }

    public function test_receipt_cannot_be_reversed_twice(): void
    {
        $id = $this->scenarioA();
        $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'x'])->assertOk();
        $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'x lagi'])->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(3, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->assertSame('0.0000', $this->stock()['available']);
    }

    public function test_reversal_is_blocked_when_stock_was_already_used(): void
    {
        $id = $this->scenarioA();
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '5', 'reason' => 'jatuh'])->assertCreated();

        $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->get_("goods-receipts/{$id}")->assertJsonPath('data.status', 'POSTED');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->assertSame('135.0000', $this->stock()['available']);
    }

    public function test_reversing_a_po_receipt_reopens_the_po(): void
    {
        $po = $this->cmd('POST', 'purchase-orders', ['supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => '100', 'unit_cost' => '23000']]])->json('data');
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk();
        $gr = $this->receive([$this->line('100', null, [], null, $po['lines'][0]['id'])], $po['id']);
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'RECEIVED');

        $this->cmd('POST', "goods-receipts/{$gr}/reverse", ['reason' => 'qty salah'])->assertOk();
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.lines.0.received_quantity', '0.0000');
        // the correct receipt can now be posted as a new document
        $this->receive([$this->line('98', null, [], null, $po['lines'][0]['id'])], $po['id']);
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'PARTIALLY_RECEIVED');
        $this->assertSame('98.0000', $this->stock()['available']);
    }

    public function test_loss_cannot_make_stock_negative(): void
    {
        $this->scenarioA();
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '140.001', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')->assertJsonPath('error.details.available', '140.0000');
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '140', 'reason' => 'busuk semua'])
            ->assertCreated()->assertJsonPath('data.type', 'LOSS')
            ->assertJsonPath('data.number', 'INV-261003-001'); // the rolled-back attempt burned no number
        $this->assertSame('0.0000', $this->stock()['available']);
        $this->assertSame(0, DB::table('inventory_operations')->where('quantity', '140.001')->count(), 'the rejected operation left nothing behind');
    }

    public function test_adjustments_and_regrade_operation(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        $this->scenarioA();

        $op = $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '2', 'direction' => 'IN', 'reason' => 'stock opname lebih'])
            ->assertCreated()->assertJsonPath('data.direction', 'IN')->json('data');
        $this->assertMatchesRegularExpression('/^INV-261003-\d{3}$/', $op['number']);
        $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'direction' => 'OUT', 'reason' => 'stock opname kurang'])->assertCreated();
        $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'direction' => 'IN'])->assertStatus(422); // reason required
        $this->assertSame('141.0000', $this->stock()['available']);

        $this->cmd('POST', 'inventory/regrades', ['node_id' => $this->node, 'product_id' => $this->egg, 'to_product_id' => $afkir, 'quantity' => '6', 'reason' => 'retak rambut'])->assertCreated();
        $this->assertSame('135.0000', $this->stock()['available']);
        $this->assertSame('6.0000', $this->stock($afkir)['available']);
        $pair = DB::table('stock_movements')->whereIn('movement_type', ['REGRADE_OUT', 'REGRADE_IN'])->where('source_document_type', 'inventory_operation')->get();
        $this->assertCount(2, $pair);
        $this->assertSame(1, $pair->pluck('correlation_id')->unique()->count());

        foreach (['inventory.adjustment' => 2, 'inventory.regrade' => 1, 'inventory.loss' => 1, 'inventory.receipt' => 1] as $action => $n) {
            $this->assertSame($n, DB::table('audit_logs')->where('action', $action)->count(), $action);
        }
        $this->assertLedgerConsistent();
    }

    public function test_there_is_no_endpoint_that_sets_stock_or_deletes_documents(): void
    {
        $id = $this->scenarioA();
        $this->cmd('PUT', 'inventory/balances', ['quantity' => 999])->assertStatus(405);
        $this->cmd('PATCH', "goods-receipts/{$id}", ['notes' => 'x'])->assertStatus(405);
        $this->cmd('DELETE', "goods-receipts/{$id}")->assertStatus(405);
        $this->assertSame('140.0000', $this->stock()['available']);
    }
}
