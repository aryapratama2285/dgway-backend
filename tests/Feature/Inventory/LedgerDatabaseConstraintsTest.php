<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bypasses the application and talks SQL directly, proving the database
 * itself refuses to corrupt the ledger and posted documents.
 */
final class LedgerDatabaseConstraintsTest extends InventoryTestCase
{
    private string $receiptId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->receiptId = $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])]);
    }

    private function assertRejected(Closure $sql, string $sqlState, string $messagePart = ''): void
    {
        try {
            DB::transaction($sql);
            $this->fail('The database accepted a forbidden statement.');
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->errorInfo[0] ?? $e->getCode(), $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function movementRow(array $override = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(), 'movement_type' => 'ADJUSTMENT_IN', 'product_id' => $this->egg, 'node_id' => $this->node,
            'bucket' => 'AVAILABLE', 'direction' => 1, 'quantity' => '1', 'uom_code' => 'KG', 'business_date' => '2026-10-03',
            'occurred_at' => now(), 'source_document_type' => 'test', 'source_document_id' => (string) Str::uuid7(), 'correlation_id' => (string) Str::uuid7(),
            // since A5-B every movement names its lot: use the receipt's lot so each test isolates the constraint it is about
            'lot_id' => DB::table('inventory_lots')->where('goods_receipt_id', $this->receiptId)->value('id'),
        ], $override);
    }

    public function test_ledger_rows_cannot_be_updated(): void
    {
        $this->assertRejected(fn () => DB::table('stock_movements')->update(['quantity' => 999]), '23001');
        $this->assertRejected(fn () => DB::statement("UPDATE stock_movements SET reason = 'x'"), '23001');
        $this->assertEquals(140.0, (float) DB::table('stock_movements')->sum('signed_quantity'));
    }

    public function test_ledger_rows_cannot_be_deleted(): void
    {
        $this->assertRejected(fn () => DB::table('stock_movements')->where('movement_type', 'LOSS')->delete(), '23001');
        $this->assertRejected(fn () => DB::table('inventory_operations')->delete() + DB::statement('DELETE FROM stock_movements'), '23001');
        $this->assertSame(3, DB::table('stock_movements')->count());
    }

    public function test_type_direction_and_quantity_checks(): void
    {
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['movement_type' => 'LOSS', 'direction' => 1])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['movement_type' => 'RECEIPT', 'direction' => -1])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['quantity' => '-1'])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['quantity' => '0'])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['movement_type' => 'SALE_MAGIC'])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['bucket' => 'LIMBO'])), '23514');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['signed_quantity' => '5'])), '428C9'); // generated column
    }

    public function test_reversal_must_mirror_its_original_and_happen_once(): void
    {
        $loss = DB::table('stock_movements')->where('movement_type', 'LOSS')->first();
        $base = ['movement_type' => 'REVERSAL', 'reverses_movement_id' => $loss->id, 'bucket' => $loss->bucket, 'direction' => 1, 'quantity' => $loss->quantity, 'lot_id' => $loss->lot_id];

        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow(['movement_type' => 'REVERSAL'])), '23514'); // no link
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow([...$base, 'quantity' => '9'])), '23514', 'mirror');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow([...$base, 'direction' => -1])), '23514', 'mirror');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow([...$base, 'bucket' => 'AVAILABLE'])), '23514', 'mirror');

        $first = $this->movementRow($base);
        DB::table('stock_movements')->insert($first);
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow($base)), '23505'); // UNIQUE reverses_movement_id
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movementRow([
            'movement_type' => 'REVERSAL', 'reverses_movement_id' => $first['id'], 'bucket' => $loss->bucket, 'direction' => -1, 'quantity' => $loss->quantity, 'lot_id' => $loss->lot_id,
        ])), '23514', 'cannot be reversed');
    }

    public function test_balance_projection_can_never_be_negative(): void
    {
        $this->assertRejected(fn () => DB::table('stock_balances')->where('bucket', 'AVAILABLE')->update(['quantity' => '-0.001']), '23514');
    }

    public function test_posted_receipt_is_immutable_and_undeletable(): void
    {
        $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $this->receiptId)->first();
        $this->assertRejected(fn () => DB::table('goods_receipt_lines')->where('id', $line->id)->update(['received_quantity' => 151, 'line_cost' => 3473000]), '23001', 'immutable');
        $this->assertRejected(fn () => DB::table('goods_receipt_dispositions')->where('goods_receipt_line_id', $line->id)->update(['quantity' => 1]), '23001', 'immutable');
        $this->assertRejected(fn () => DB::table('goods_receipt_dispositions')->where('goods_receipt_line_id', $line->id)->delete(), '23001');
        $this->assertRejected(fn () => DB::table('goods_receipts')->where('id', $this->receiptId)->delete(), '23001');
        $other = $this->supplier('SUP-LAIN');
        $this->assertRejected(fn () => DB::table('goods_receipts')->where('id', $this->receiptId)->update(['supplier_id' => $other, 'version' => 99]), '23001');
        $this->assertRejected(fn () => DB::table('goods_receipts')->where('id', $this->receiptId)->update(['status' => 'DRAFT', 'posted_at' => null]), '23514');
    }

    public function test_receipt_cannot_be_posted_with_unbalanced_dispositions(): void
    {
        $id = $this->createReceipt([$this->line('20')])->assertCreated()->json('data.id');
        $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $id)->first();
        // details are append-only even in DRAFT, but rows may still be added to a draft
        $this->assertRejected(fn () => DB::table('goods_receipt_dispositions')->where('goods_receipt_line_id', $line->id)->update(['quantity' => 19]), '23001');
        DB::table('goods_receipt_dispositions')->insert(['id' => (string) Str::uuid7(), 'goods_receipt_line_id' => $line->id, 'disposition_type' => 'LOSS', 'quantity' => 1]);
        $this->assertRejected(fn () => DB::table('goods_receipts')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now(), 'version' => 2]), '23514', 'MASS_BALANCE');
    }

    public function test_purchase_order_line_rules(): void
    {
        $po = $this->cmd('POST', 'purchase-orders', ['supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => '150', 'unit_cost' => '23000']]])->json('data');
        $lineId = $po['lines'][0]['id'];
        $this->assertSame('3450000.0000', (string) DB::table('purchase_order_lines')->where('id', $lineId)->value('line_total'));

        $this->assertRejected(fn () => DB::table('purchase_order_lines')->where('id', $lineId)->update(['received_quantity' => 151]), '23514');
        $this->assertRejected(fn () => DB::table('purchase_order_lines')->where('id', $lineId)->update(['unit_cost' => 1, 'line_total' => 150]), '23001');
        $this->assertRejected(fn () => DB::table('purchase_order_lines')->where('id', $lineId)->delete(), '23001');
        $this->assertRejected(fn () => DB::table('purchase_orders')->where('id', $po['id'])->delete(), '23001');
        $this->assertRejected(fn () => DB::table('goods_receipt_lines')->insert([
            'id' => (string) Str::uuid7(), 'goods_receipt_id' => $this->createReceipt([$this->line('1')])->json('data.id'), 'line_no' => 9,
            'product_id' => $this->product('TELUR-X'), 'received_quantity' => 150, 'uom_code' => 'KG', 'unit_cost' => 23000, 'line_cost' => 3400000,
        ]), '23514'); // line_cost must equal round(qty × cost, 4)
    }
}
