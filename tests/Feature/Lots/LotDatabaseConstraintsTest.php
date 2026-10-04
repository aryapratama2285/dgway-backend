<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A5-B Part 14: the DATABASE refuses lot corruption even when the
 * application is bypassed (raw SQL).
 */
final class LotDatabaseConstraintsTest extends LotTestCase
{
    private string $gr;

    private string $lot;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->gr, $this->lot] = $this->receiveOn('2026-10-03', '150', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]);
    }

    private function assertRejected(Closure $sql, string $sqlState, string $messagePart = ''): void
    {
        try {
            DB::transaction(function () use ($sql) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // deferred (commit-time) checks fire inside the test transaction
                $sql();
            });
            $this->fail('The database accepted a forbidden statement.');
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->errorInfo[0] ?? $e->getCode(), $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }

    private function movement(array $override): array
    {
        return array_replace([
            'id' => (string) Str::uuid7(), 'movement_type' => 'LOSS', 'product_id' => $this->egg, 'node_id' => $this->node,
            'bucket' => 'AVAILABLE', 'direction' => -1, 'quantity' => '1', 'uom_code' => 'KG', 'business_date' => '2026-10-03',
            'occurred_at' => now(), 'source_document_type' => 'test', 'source_document_id' => (string) Str::uuid7(), 'correlation_id' => (string) Str::uuid7(),
            'lot_id' => $this->lot,
        ], $override);
    }

    public function test_lot_identity_and_provenance_are_immutable_and_undeletable(): void
    {
        $this->assertRejected(fn () => DB::table('inventory_lots')->where('id', $this->lot)->update(['number' => 'LOT-000000-999']), '23001');
        $this->assertRejected(fn () => DB::table('inventory_lots')->where('id', $this->lot)->update(['supplier_id' => null]), '23001');
        $this->assertRejected(fn () => DB::table('inventory_lots')->where('id', $this->lot)->delete(), '23001');
    }

    public function test_receipt_lot_must_match_its_receipt_line(): void
    {
        $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $this->gr)->first();
        $other = $this->product('TELUR-AFKIR');
        $base = ['number' => 'LOT-261003-900', 'origin' => 'RECEIPT', 'node_id' => $this->node, 'uom_code' => 'KG', 'original_quantity' => 150,
            'supplier_id' => $this->supplier, 'goods_receipt_id' => $this->gr, 'goods_receipt_line_id' => $line->id, 'source_document_type' => 'goods_receipt',
            'source_document_id' => $this->gr, 'business_date' => '2026-10-03', 'received_at' => now()];
        // second lot for the same line
        $this->assertRejected(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'product_id' => $this->egg] + $base), '23505');
        // provenance invented: wrong product for the receipt line
        $this->assertRejected(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'product_id' => $other] + $base), '23514', 'LOT_PROVENANCE');
        // RECEIPT lot without supplier
        $this->assertRejected(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'product_id' => $this->egg, 'supplier_id' => null] + $base), '23514');
        // best-before before manufacture
        $this->assertRejected(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'product_id' => $this->egg, 'manufactured_date' => '2026-10-02', 'best_before_date' => '2026-10-01'] + $base), '23514');
    }

    public function test_movement_cannot_use_a_lot_of_another_product_or_create_quantity_in_a_foreign_lot(): void
    {
        $other = $this->product('TELUR-AFKIR');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movement(['product_id' => $other])), '23514', 'LOT_PRODUCT_MISMATCH');
        // stock may only be CREATED in a lot by the document that created the lot
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movement(['movement_type' => 'ADJUSTMENT_IN', 'direction' => 1])), '23514', 'LOT_SOURCE_MISMATCH');
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movement(['movement_type' => 'RECEIPT', 'direction' => 1, 'source_document_type' => 'goods_receipt'])), '23514', 'LOT_SOURCE_MISMATCH');
        // a post-cut-over movement must name its lot
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movement(['lot_id' => null])), '23514', 'sm_lot_required_check');
    }

    public function test_lot_original_quantity_must_equal_its_creating_movements(): void
    {
        // an extra RECEIPT into the lot from its own receipt still breaks "original = created"
        $this->assertRejected(fn () => DB::table('stock_movements')->insert($this->movement([
            'movement_type' => 'RECEIPT', 'direction' => 1, 'source_document_type' => 'goods_receipt', 'source_document_id' => $this->gr,
        ])), '23514', 'LOT_ORIGINAL_QUANTITY');
    }

    public function test_lot_balance_projection_can_never_be_negative(): void
    {
        $this->assertRejected(fn () => DB::table('stock_lot_balances')->where('lot_id', $this->lot)->where('bucket', 'AVAILABLE')->update(['quantity' => '-0.001']), '23514');
    }
}
