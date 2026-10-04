<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A5-C addendum §1 — a DG Way lot is DG Way's internal lot; collection_date
 * and supplier_reference are OPTIONAL upstream facts, never inferred.
 * collection_date ≠ received date.
 */
final class CollectionDateTest extends LotTestCase
{
    private function lot(string $id): object
    {
        return DB::table('inventory_lots')->where('id', $id)->first();
    }

    /** Case A — agent / distributor: nothing known upstream. */
    public function test_agent_sourced_lot_keeps_collection_date_null_and_never_takes_the_received_date(): void
    {
        [$gr, $lot] = $this->receiveOn('2026-10-03', '150', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]);
        $l = $this->lot($lot);
        $this->assertNull($l->collection_date);
        $this->assertNull($l->supplier_reference);
        $this->assertSame('2026-10-03', (string) $l->business_date, 'received date recorded separately');
        $this->assertNull(DB::table('goods_receipt_lines')->where('goods_receipt_id', $gr)->value('collection_date'));
        $this->get_("lots/{$lot}")->assertJsonPath('data.collection_date', null)->assertJsonPath('data.supplier_reference', null)
            ->assertJsonPath('data.business_date', '2026-10-03');
    }

    /** Case B — direct farm: collected 2026-10-02, received 2026-10-03, no supplier batch number. */
    public function test_direct_farm_lot_keeps_collection_date_without_inventing_a_supplier_batch(): void
    {
        $res = $this->createReceipt([array_merge($this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]),
            ['collection_date' => '2026-10-02'])])->assertCreated();
        $res->assertJsonPath('data.lines.0.collection_date', '2026-10-02')->assertJsonPath('data.lines.0.supplier_reference', null);
        $this->cmd('POST', 'goods-receipts/'.$res->json('data.id').'/post')->assertOk();
        $l = DB::table('inventory_lots')->where('goods_receipt_id', $res->json('data.id'))->first();
        $this->assertSame(['2026-10-02', '2026-10-03', null], [(string) $l->collection_date, (string) $l->business_date, $l->supplier_reference]);
        $this->assertStringStartsWith('LOT-261003-', $l->number, 'the DG Way lot number is DG Way\'s, dated by the receipt — not a supplier batch');
        $this->get_("lots/{$l->id}")->assertJsonPath('data.collection_date', '2026-10-02')->assertJsonPath('data.business_date', '2026-10-03');

        // trace shows both facts, labelled differently
        Artisan::call('dgway:trace', ['reference' => $l->number]);
        $out = Artisan::output();
        $this->assertStringContainsString('Received by DG Way: 2026-10-03', $out);
        $this->assertStringContainsString('Collection date: 2026-10-02', $out);
        $this->assertStringContainsString('Supplier reference: none', $out);
    }

    /** Case C — a supplier that really gives its own batch reference; it stays separate from the DG Way lot identity. */
    public function test_supplier_reference_is_stored_only_when_given_and_inherited_by_a_regrade_lot(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [, $lot] = $this->receiveOn('2026-10-03', '100', [['type' => 'ACCEPT', 'quantity' => '90'], ['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $afkir]], null,
            ['collection_date' => '2026-10-01', 'supplier_reference' => '  FARM-B  7  ']);
        $this->assertSame(['2026-10-01', 'FARM-B 7'], [(string) $this->lot($lot)->collection_date, $this->lot($lot)->supplier_reference]);
        $child = DB::table('inventory_lots')->where('parent_lot_id', $lot)->first();
        $this->assertSame(['2026-10-01', 'FARM-B 7'], [(string) $child->collection_date, $child->supplier_reference], 'same goods → same upstream facts');
        $this->assertNotSame('FARM-B 7', $child->number);

        // an adjustment-found lot never carries upstream facts
        $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '3', 'direction' => 'IN', 'reason' => 'stock opname'])->assertCreated();
        $adj = DB::table('inventory_lots')->where('origin', 'ADJUSTMENT')->first();
        $this->assertSame([null, null], [$adj->collection_date, $adj->supplier_reference]);
    }

    public function test_impossible_collection_dates_are_rejected_and_reasonable_ones_accepted(): void
    {
        $line = fn (array $extra) => array_merge($this->line('10'), $extra);
        // collected AFTER DG Way received it (receipt date 2026-10-03)
        $this->createReceipt([$line(['collection_date' => '2026-10-04'])])->assertStatus(422)->assertJsonPath('error.code', 'COLLECTION_DATE_INVALID')
            ->assertJsonPath('error.details.rule', 'not_after_receipt');
        // collected before it was produced
        $this->createReceipt([$line(['manufactured_date' => '2026-10-02', 'collection_date' => '2026-10-01'])])->assertStatus(422)
            ->assertJsonPath('error.code', 'COLLECTION_DATE_INVALID')->assertJsonPath('error.details.rule', 'not_before_manufactured');
        $this->createReceipt([$line(['collection_date' => '03-10-2026'])])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->createReceipt([$line(['supplier_reference' => str_repeat('X', 61)])])->assertStatus(422);
        // same day and an older date (e.g. eggs held at the farm) are real operations → accepted
        $this->createReceipt([$line(['collection_date' => '2026-10-03'])])->assertCreated();
        $this->createReceipt([array_merge($this->line('10', '23000', [], $this->product('TELUR-B')), ['collection_date' => '2026-09-20'])])->assertCreated();
        // a back-dated receipt is checked against ITS date, not today
        $this->cmd('POST', 'goods-receipts', ['supplier_id' => $this->supplier, 'node_id' => $this->node, 'business_date' => '2026-09-30',
            'lines' => [$line(['collection_date' => '2026-10-01'])]])->assertStatus(422)->assertJsonPath('error.code', 'COLLECTION_DATE_INVALID');
    }

    public function test_the_database_refuses_invented_upstream_facts(): void
    {
        [$gr, $lot] = $this->receiveOn('2026-10-03', '50', null, null, ['collection_date' => '2026-10-02']);
        $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $gr)->first();
        $reject = function (Closure $sql, string $part) {
            try {
                DB::transaction($sql);
                $this->fail('accepted');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
                $this->assertStringContainsString($part, $e->getMessage());
            }
        };
        $base = ['number' => 'LOT-261003-901', 'origin' => 'RECEIPT', 'product_id' => $this->egg, 'node_id' => $this->node, 'uom_code' => 'KG', 'original_quantity' => 50,
            'supplier_id' => $this->supplier, 'goods_receipt_id' => $gr, 'goods_receipt_line_id' => $line->id, 'source_document_type' => 'goods_receipt',
            'source_document_id' => $gr, 'business_date' => '2026-10-03', 'received_at' => now()];
        DB::statement('DROP INDEX lot_one_per_receipt_line'); // isolate the upstream rule from the one-lot-per-line rule (rolled back below)
        try {
            $reject(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'collection_date' => '2026-10-01'] + $base), 'LOT_UPSTREAM_MISMATCH');
            $reject(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'collection_date' => '2026-10-02', 'supplier_reference' => 'X'] + $base), 'LOT_UPSTREAM_MISMATCH');
            // CHECK: a lot without a supplier origin can never carry upstream facts
            $reject(fn () => DB::table('inventory_lots')->insert(['id' => (string) Str::uuid7(), 'number' => 'LOT-261003-902', 'origin' => 'ADJUSTMENT', 'product_id' => $this->egg,
                'node_id' => $this->node, 'uom_code' => 'KG', 'original_quantity' => 1, 'source_document_type' => 'inventory_operation',
                'source_document_id' => (string) Str::uuid7(), 'business_date' => '2026-10-03', 'received_at' => now(), 'collection_date' => '2026-10-01']), 'lot_upstream_check');
        } finally {
            DB::statement("CREATE UNIQUE INDEX lot_one_per_receipt_line ON inventory_lots (goods_receipt_line_id) WHERE origin = 'RECEIPT'");
        }
        // lot identity (incl. upstream facts) stays immutable
        $reject2 = fn () => DB::table('inventory_lots')->where('id', $lot)->update(['collection_date' => '2026-10-01']);
        try {
            DB::transaction($reject2);
            $this->fail('accepted');
        } catch (QueryException $e) {
            $this->assertSame('23001', $e->errorInfo[0]);
        }
    }

    /** The A5-C migration adds the columns empty: nothing is backfilled from receipt/received/created dates. */
    public function test_migration_never_fabricates_collection_dates_for_existing_lots(): void
    {
        $this->receiveOn('2026-10-01', '40');
        $this->receiveOn('2026-10-02', '40', null, $this->product('TELUR-B'), ['collection_date' => '2026-10-01']);
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_05_100100_add_lot_upstream_facts')->count();
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]));
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('inventory_lots', 'collection_date'));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $rows = DB::table('inventory_lots')->get(['collection_date', 'supplier_reference', 'business_date', 'received_at']);
            $this->assertCount(2, $rows);
            foreach ($rows as $r) {
                $this->assertNull($r->collection_date, 'existing lot: collection date stays unknown');
                $this->assertNull($r->supplier_reference);
                $this->assertNotNull($r->business_date);
            }
            $this->assertSame(0, DB::table('goods_receipt_lines')->whereNotNull('collection_date')->count());
        } finally {
            Artisan::call('migrate', ['--force' => true]);
        }
    }
}
