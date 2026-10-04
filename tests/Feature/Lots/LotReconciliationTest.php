<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A5-B Part 26 #14–#15 — lot fingerprint reconciliation and a REAL
 * rollback/re-migrate of the A5-B migration over pre-A5-B ledger history.
 */
final class LotReconciliationTest extends LotTestCase
{
    private const MIGRATION = '2026_10_04_100100_create_inventory_lots';

    /**
     * Number of applied migrations from the A5-B lot migration onward (A5-C added
     * later migrations; rolling back only one step would no longer reach A5-B).
     */
    private function stepsBackToBeforeA5B(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::MIGRATION)->count();
    }

    private function fingerprint(array $args = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'fp');
        $this->assertSame(0, Artisan::call('dgway:data:fingerprint', ['--output' => $file] + $args));

        return [$file, json_decode((string) file_get_contents($file), true)];
    }

    private function compare(string $file, array $args = []): array
    {
        $code = Artisan::call('dgway:data:fingerprint', ['--compare' => $file] + $args);

        return [$code, Artisan::output()];
    }

    public function test_lot_invariants_reconcile_and_tampering_is_detected(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [, $a] = $this->receiveOn('2026-10-01', '100', [['type' => 'ACCEPT', 'quantity' => '90'], ['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $afkir]]);
        [, $b] = $this->receiveOn('2026-10-02', '50');
        $sale = $this->sell(null, '120', '27000', true, [$this->cash('3240000')])->json('data');
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'x', 'refund_method' => 'CASH', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '5', 'disposition' => 'RETURN_TO_AVAILABLE']]])->assertCreated();

        [$file, $fp] = $this->fingerprint();
        $lots = $fp['invariants']['lots'];
        $this->assertSame(['original' => '160.0000', 'created' => '160.0000'], $lots['original_vs_created'], 'Σ original = Σ creating movements (100 + 10 regrade + 50)');
        $this->assertSame([['origin' => 'RECEIPT', 'n' => 2, 'original' => '150.0000'], ['origin' => 'REGRADE', 'n' => 1, 'original' => '10.0000']], $lots['by_origin']);
        $this->assertSame(['edges' => 1], array_intersect_key($lots['lineage'], ['edges' => 1]));
        $this->assertSame(0, $lots['unattributed_movements']['n']);
        // ledger-derived remaining per lot = the lot projection served by the API
        $api = collect($this->get_('inventory/lot-balances?per_page=100')->json('data'))->mapWithKeys(fn ($r) => [$r['lot_id'].'|'.$r['bucket'] => $r['quantity']]);
        $this->assertSame(['5.0000', '20.0000'], [$api["{$a}|AVAILABLE"], $api["{$b}|AVAILABLE"]], 'FIFO: A gave 90, B gave 30; 5 returned to A');

        // rebuilding projections is not a change of history
        Artisan::call('dgway:inventory:rebuild-balances', ['--force' => true]);
        [$c, $o] = $this->compare($file); $this->assertSame(0, $c, $o);

        // projection drift is caught by verify (and repaired by rebuild)
        DB::table('stock_lot_balances')->where('lot_id', $b)->where('bucket', 'AVAILABLE')->update(['quantity' => '21']);
        $this->assertSame(1, Artisan::call('dgway:inventory:verify'));
        $this->assertStringContainsString($b, Artisan::output());
        Artisan::call('dgway:inventory:rebuild-balances', ['--force' => true]);
        $this->assertSame(0, Artisan::call('dgway:inventory:verify'));

        // a migration that re-attributes history to another lot is caught by the fingerprint
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_append_only');
            DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_lot_guard');
            DB::table('stock_movements')->where('movement_type', 'CUSTOMER_RETURN')->update(['lot_id' => $b]);
            [$code, $out] = $this->compare($file);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('invariants.lots.remaining_by_lot.md5', $out);
            $this->assertStringNotContainsString('stock_by_position', $out, 'product quantities are untouched; only the lot dimension changed');
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, $this->compare($file)[0]);
    }

    public function test_rollback_and_remigrate_over_pre_a5b_history_reconcile(): void
    {
        $customer = $this->customer('CUS-ABC', 7);
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => $this->stepsBackToBeforeA5B(), '--force' => true]));
            $this->assertFalse((bool) DB::selectOne("SELECT to_regclass('public.inventory_lots') IS NOT NULL AS e")->e);

            // pre-A5-B history written as A4 wrote it (no lot column): receipt 100 → 70 left after a 30 sale
            $gr = (string) Str::uuid7();
            $sale = (string) Str::uuid7();
            foreach ([['RECEIPT', 1, '100', 'goods_receipt', $gr], ['SALE', -1, '30', 'sale', $sale]] as [$type, $dir, $qty, $src, $doc]) {
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid7(), 'movement_type' => $type, 'product_id' => $this->egg, 'node_id' => $this->node, 'bucket' => 'AVAILABLE',
                    'direction' => $dir, 'quantity' => $qty, 'uom_code' => 'KG', 'business_date' => '2026-09-30', 'occurred_at' => '2026-09-30 03:00:00+00',
                    'source_document_type' => $src, 'source_document_id' => $doc, 'correlation_id' => $doc,
                ]);
            }
            DB::table('stock_balances')->insert(['product_id' => $this->egg, 'node_id' => $this->node, 'bucket' => 'AVAILABLE', 'uom_code' => 'KG', 'quantity' => '70', 'updated_at' => now()]);
            [$before] = $this->fingerprint();

            // up: additions only, history untouched, one LEGACY lot holds the 70
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            [$code, $out] = $this->compare($before, ['--allow-additions' => true]);
            $this->assertSame(0, $code, $out);
            $this->assertStringContainsString('added (accepted): invariants.lots', $out);
            [$afterUp, $fp] = $this->fingerprint();
            $this->assertSame([['origin' => 'LEGACY', 'n' => 1, 'original' => '0.0000']], $fp['invariants']['lots']['by_origin']);
            $this->assertSame(2, $fp['invariants']['lots']['unattributed_movements']['n']);
            $this->assertSame(0, Artisan::call('dgway:inventory:verify'));

            // down: strictly identical to before; up again: strictly identical to the first up (deterministic legacy lot)
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => $this->stepsBackToBeforeA5B(), '--force' => true]));
            [$code, $out] = $this->compare($before);
            $this->assertSame(0, $code, $out);
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            [$code, $out] = $this->compare($afterUp);
            $this->assertSame(0, $code, $out);

            // the legacy stock is sellable afterwards, FIFO, and a new lot-attributed movement must name its lot
            $legacy = (string) DB::table('inventory_lots')->where('origin', 'LEGACY')->value('id');
            $s = $this->sell($customer, '20', '25000', true, [$this->cash('500000')])->assertCreated()->json('data');
            $this->assertSame([['lot_id' => $legacy, 'qty' => '20.0000']], $this->saleAllocation($s['id']));
            $this->assertSame('50.0000', $this->lotQty($legacy));
            $this->assertSame(0, Artisan::call('dgway:inventory:verify'));
        } finally {
            if (! DB::selectOne("SELECT to_regclass('public.inventory_lots') IS NOT NULL AS e")->e) {
                Artisan::call('migrate', ['--force' => true]); // leave the schema as the rest of the suite expects
            }
        }
    }
}
