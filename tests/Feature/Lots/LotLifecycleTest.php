<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LotLifecycleTest extends LotTestCase
{
    public function test_lot_is_created_exactly_once_when_the_receipt_is_posted(): void
    {
        $gr = $this->createReceipt([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])])->json('data');
        $this->assertSame(0, DB::table('inventory_lots')->count(), 'a draft receipt creates no lot');

        $this->cmd('POST', "goods-receipts/{$gr['id']}/post")->assertOk();
        $lot = DB::table('inventory_lots')->first();
        $this->assertSame('RECEIPT', $lot->origin);
        $this->assertSame('LOT-261003-001', $lot->number);
        $this->assertEquals(150, (float) $lot->original_quantity);
        $this->assertSame($this->supplier, $lot->supplier_id);
        $this->assertSame($gr['id'], $lot->goods_receipt_id);
        $this->assertSame($gr['lines'][0]['id'], $lot->goods_receipt_line_id);
        $this->assertSame($this->egg, $lot->product_id);

        // disposition consequences, all inside the lot: original 150 = 140 AVAILABLE + 10 written off
        $moves = DB::table('stock_movements')->where('source_document_id', $gr['id'])->get();
        $this->assertSame([$lot->id], $moves->pluck('lot_id')->unique()->values()->all());
        $this->assertEquals(150, (float) $moves->where('movement_type', 'RECEIPT')->sum('quantity'));
        $this->assertEquals(10, (float) $moves->where('movement_type', 'LOSS')->sum('quantity'));
        $this->assertSame('140.0000', $this->lotQty($lot->id));
        $this->assertSame('0.0000', $this->lotQty($lot->id, 'QUARANTINE'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inventory_lot.created')->count());
        $this->assertLedgerConsistent();
    }

    public function test_retried_or_repeated_posting_never_creates_a_second_lot(): void
    {
        $gr = $this->createReceipt([$this->line('50')])->json('data');
        $key = (string) Str::uuid();
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", [], $key)->assertOk();
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true'); // response lost → retry
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post")->assertStatus(409);                                                // new key → business conflict
        $this->assertSame(1, DB::table('inventory_lots')->count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('prefix', 'LOT')->value('last_value'), 'failed attempts burn no lot number');
    }

    public function test_regrade_at_receipt_creates_a_derived_lot_with_lineage(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [$gr, $lotA] = $this->receiveOn('2026-10-03', '100', [
            ['type' => 'ACCEPT', 'quantity' => '85'], ['type' => 'REGRADE', 'quantity' => '15', 'target_product_id' => $afkir],
        ]);
        $child = DB::table('inventory_lots')->where('origin', 'REGRADE')->first();
        $this->assertSame($lotA, $child->parent_lot_id);
        $this->assertSame($afkir, $child->product_id);
        $this->assertSame($this->supplier, $child->supplier_id, 'provenance of a derived lot = its root supplier');
        $this->assertEquals(15, (float) $child->original_quantity);

        $out = DB::table('stock_movements')->where('movement_type', 'REGRADE_OUT')->first();
        $in = DB::table('stock_movements')->where('movement_type', 'REGRADE_IN')->first();
        $this->assertSame($lotA, $out->lot_id);
        $this->assertSame($child->id, $in->lot_id);
        $this->assertSame($out->correlation_id, $in->correlation_id);
        $this->assertSame('85.0000', $this->lotQty($lotA));
        $this->assertSame('15.0000', $this->lotQty($child->id));
    }

    public function test_regrade_operation_over_two_lots_creates_one_derived_lot_per_parent(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [, $lotA] = $this->receiveOn('2026-10-01', '4');
        [, $lotB] = $this->receiveOn('2026-10-02', '10');
        $this->cmd('POST', 'inventory/regrades', ['node_id' => $this->node, 'product_id' => $this->egg, 'to_product_id' => $afkir, 'quantity' => '6', 'reason' => 'retak'])->assertCreated();

        $children = DB::table('inventory_lots')->where('origin', 'REGRADE')->orderBy('number')->get();
        $this->assertSame([$lotA, $lotB], $children->pluck('parent_lot_id')->all(), 'FIFO: oldest lot regraded first');
        $this->assertSame(['4.0000', '2.0000'], $children->map(fn ($c) => (string) $c->original_quantity)->all());
        $this->assertSame('0.0000', $this->lotQty($lotA));
        $this->assertSame('8.0000', $this->lotQty($lotB));
    }

    public function test_fifo_allocation_from_one_lot_and_from_several_lots(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '20');
        [, $lotB] = $this->receiveOn('2026-10-03', '50');

        $s1 = $this->sell(null, '15', '26000', true, [$this->cash('390000')])->assertCreated()->json('data');
        $this->assertSame([['lot_id' => $lotA, 'qty' => '15.0000']], $this->saleAllocation($s1['id']));

        $s2 = $this->sell(null, '30', '26000', true, [$this->cash('780000')])->assertCreated()->json('data');
        $this->assertSame([['lot_id' => $lotA, 'qty' => '5.0000'], ['lot_id' => $lotB, 'qty' => '25.0000']], $this->saleAllocation($s2['id']));
        $this->assertSame('0.0000', $this->lotQty($lotA));
        $this->assertSame('25.0000', $this->lotQty($lotB));

        // the classic example: Lot A 20 (1 Oct) + Lot B 50 (3 Oct), sale 30 → 20 A + 10 B
        [, $lotC] = $this->receiveOn('2026-10-04', '20');
        $this->assertLedgerConsistent();
        unset($lotC);
    }

    public function test_brief_example_20_from_lot_a_and_10_from_lot_b(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '20');
        [, $lotB] = $this->receiveOn('2026-10-03', '50');
        $s = $this->sell(null, '30', '26000', true, [$this->cash('780000')])->json('data');
        $this->assertSame([['lot_id' => $lotA, 'qty' => '20.0000'], ['lot_id' => $lotB, 'qty' => '10.0000']], $this->saleAllocation($s['id']));
    }

    public function test_sale_retry_does_not_allocate_twice(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '50');
        $key = (string) Str::uuid();
        $this->sell(null, '30', '26000', true, [$this->cash('780000')], $key)->assertCreated();
        $this->sell(null, '30', '26000', true, [$this->cash('780000')], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame('20.0000', $this->lotQty($lot));
    }

    public function test_return_goes_back_to_the_lots_it_was_sold_from(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '20');
        [, $lotB] = $this->receiveOn('2026-10-03', '50');
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '30', '25000', true)->json('data'); // 20 A + 10 B
        $line = $sale['lines'][0]['id'];

        // policy: allocation order — lot A first (up to 20), then lot B
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'kelebihan', 'lines' => [['sale_line_id' => $line, 'quantity' => '15', 'disposition' => 'RETURN_TO_AVAILABLE']]])->assertCreated();
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'pecah', 'lines' => [['sale_line_id' => $line, 'quantity' => '8', 'disposition' => 'LOSS']]])->assertCreated();

        $r = DB::table('stock_movements')->where('movement_type', 'CUSTOMER_RETURN')->orderBy('sequence')->get(['lot_id', 'quantity', 'bucket']);
        $this->assertSame([[$lotA, '15.0000', 'AVAILABLE'], [$lotA, '5.0000', 'QUARANTINE'], [$lotB, '3.0000', 'QUARANTINE']],
            $r->map(fn ($m) => [$m->lot_id, (string) $m->quantity, $m->bucket])->all());
        $this->assertSame('15.0000', $this->lotQty($lotA));          // 20 − 20 sold + 15 back
        $this->assertSame('40.0000', $this->lotQty($lotB));          // 50 − 10 sold
        $this->assertSame('0.0000', $this->lotQty($lotB, 'QUARANTINE')); // broken eggs written off inside the lot
        $this->assertNull(DB::table('inventory_lots')->where('origin', 'RETURN')->first(), 'returns never create unrelated lots');

        $retKey = (string) Str::uuid();
        $body = ['reason' => 'x', 'lines' => [['sale_line_id' => $line, 'quantity' => '1', 'disposition' => 'RETURN_TO_AVAILABLE']]];
        $this->cmd('POST', "sales/{$sale['id']}/returns", $body, $retKey)->assertCreated();
        $this->cmd('POST', "sales/{$sale['id']}/returns", $body, $retKey)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(4, DB::table('stock_movements')->where('movement_type', 'CUSTOMER_RETURN')->count(), 'retry adds no return movement');
        $this->assertLedgerConsistent();
    }

    public function test_operations_consume_lots_fifo_and_adjustment_in_creates_its_own_lot(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '10', [['type' => 'ACCEPT', 'quantity' => '6'], ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => '4']]);
        [, $lotB] = $this->receiveOn('2026-10-02', '10', [['type' => 'ACCEPT', 'quantity' => '7'], ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => '3']]);

        $this->cmd('POST', 'inventory/supplier-returns', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '5', 'reason' => 'retur'])->assertCreated();
        $this->assertSame(['0.0000', '2.0000'], [$this->lotQty($lotA, 'RETURN_PENDING'), $this->lotQty($lotB, 'RETURN_PENDING')]);

        $this->cmd('POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '2', 'from_bucket' => 'RETURN_PENDING', 'reason' => 'layak'])->assertCreated();
        $this->assertSame(['9.0000', '0.0000'], [$this->lotQty($lotB), $this->lotQty($lotB, 'RETURN_PENDING')], 'recovery keeps the lot');

        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '8', 'reason' => 'jatuh'])->assertCreated();
        $this->assertSame(['0.0000', '7.0000'], [$this->lotQty($lotA), $this->lotQty($lotB)]);

        $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '2', 'direction' => 'IN', 'reason' => 'opname lebih'])->assertCreated();
        $adj = DB::table('inventory_lots')->where('origin', 'ADJUSTMENT')->first();
        $this->assertNull($adj->supplier_id, 'no supplier is invented for a stock-count surplus');
        $this->assertSame('2.0000', $this->lotQty($adj->id));

        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '9.001', 'reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertLedgerConsistent();
    }

    public function test_reversals_restore_the_same_lots(): void
    {
        [$gr, $lotA] = $this->receiveOn('2026-10-01', '20');
        $s = $this->sell(null, '5', '26000', true, [$this->cash('130000')])->json('data');
        $this->cmd('POST', "sales/{$s['id']}/reverse", ['reason' => 'salah', 'reverse_payments' => true])->assertOk();
        $this->assertSame('20.0000', $this->lotQty($lotA));
        $rev = DB::table('stock_movements')->where('movement_type', 'REVERSAL')->first();
        $this->assertSame($lotA, $rev->lot_id);

        $this->cmd('POST', "goods-receipts/{$gr}/reverse", ['reason' => 'salah supplier'])->assertOk();
        $this->assertSame('0.0000', $this->lotQty($lotA));
        $this->assertSame(1, DB::table('inventory_lots')->count(), 'the lot stays as history (status derived: depleted)');
    }

    public function test_pre_lot_history_belongs_to_a_legacy_lot_and_is_consumed_first(): void
    {
        // Simulate data written before A5-B: a movement without lot, created before the cut-over, plus its LEGACY lot.
        $legacy = (string) Str::uuid7();
        $receiptId = (string) Str::uuid7();
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid7(), 'movement_type' => 'ADJUSTMENT_IN', 'product_id' => $this->egg, 'node_id' => $this->node,
            'bucket' => 'AVAILABLE', 'direction' => 1, 'quantity' => 12, 'uom_code' => 'KG', 'business_date' => '2026-09-01', 'occurred_at' => '2026-09-01 03:00:00+00',
            'source_document_type' => 'inventory_operation', 'source_document_id' => $receiptId, 'correlation_id' => $receiptId, 'created_at' => '2026-09-01 03:00:00+00']);
        DB::table('inventory_lots')->insert(['id' => $legacy, 'number' => 'LOT-260901-001', 'origin' => 'LEGACY', 'product_id' => $this->egg, 'node_id' => $this->node,
            'uom_code' => 'KG', 'source_document_type' => 'legacy_backfill', 'business_date' => '2026-09-01', 'received_at' => '2026-09-01 03:00:00+00']);
        $this->artisan('dgway:inventory:rebuild-balances', ['--force' => true])->assertSuccessful();
        // a new movement without lot is impossible now
        $this->assertThrows(fn () => DB::table('stock_movements')->insert(['id' => (string) Str::uuid7(), 'movement_type' => 'ADJUSTMENT_IN', 'product_id' => $this->egg,
            'node_id' => $this->node, 'bucket' => 'AVAILABLE', 'direction' => 1, 'quantity' => 1, 'uom_code' => 'KG', 'business_date' => '2026-10-03', 'occurred_at' => now(),
            'source_document_type' => 'test', 'source_document_id' => $receiptId, 'correlation_id' => $receiptId]), \Illuminate\Database\QueryException::class, 'sm_lot_required_check');

        [, $lotA] = $this->receiveOn('2026-10-02', '10');
        $s = $this->sell(null, '15', '26000', true, [$this->cash('390000')])->json('data');
        $this->assertSame([['lot_id' => $legacy, 'qty' => '12.0000'], ['lot_id' => $lotA, 'qty' => '3.0000']], $this->saleAllocation($s['id']), 'legacy (oldest) first');
        $this->assertSame('0.0000', $this->lotQty($legacy));
        $this->assertLedgerConsistent();
    }

    /** Replaces the A3 unit assertion changed in InventoryDomainTest: a regrade can never change the unit (no derived lot in another UOM). */
    public function test_regrade_to_a_product_with_another_base_uom_is_refused_without_side_effects(): void
    {
        $this->receiveOn('2026-10-03', '20');
        $butir = $this->product('TELUR-BUTIR', null, 'PCS');
        $before = DB::table('stock_movements')->count();
        $this->cmd('POST', 'inventory/regrades', ['node_id' => $this->node, 'product_id' => $this->egg, 'to_product_id' => $butir, 'quantity' => '5', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'UOM_NOT_BASE');
        $this->assertSame($before, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('inventory_lots')->where('origin', 'REGRADE')->count());
    }
}
