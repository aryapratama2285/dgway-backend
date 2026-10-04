<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class ProjectionAndRegistryTest extends InventoryTestCase
{
    public function test_verify_detects_drift_and_rebuild_restores_from_ledger(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        $this->receive([$this->line('150', '23000', [
            ['type' => 'ACCEPT', 'quantity' => '120'], ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => '20'],
            ['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $afkir],
        ])]);
        $this->assertSame(0, Artisan::call('dgway:inventory:verify'));

        // simulate drift in the projection (the ledger itself cannot be edited)
        DB::table('stock_balances')->where('product_id', $this->egg)->where('bucket', 'AVAILABLE')->update(['quantity' => 999]);
        DB::table('stock_balances')->where('product_id', $afkir)->delete();

        $res = $this->get_('inventory/integrity')->assertOk()->assertJsonPath('data.consistent', false)->json('data.mismatches');
        $this->assertCount(2, $res);
        $this->assertSame(1, Artisan::call('dgway:inventory:verify'));

        $this->assertSame(0, Artisan::call('dgway:inventory:rebuild-balances', ['--force' => true]));
        $this->assertLedgerConsistent();
        $this->assertSame('120.0000', $this->stock()['available']);
        $this->assertSame('20.0000', $this->stock()['return_pending']);
        $this->assertSame('10.0000', $this->stock($afkir)['available']);
    }

    public function test_balance_always_equals_ledger_sum_under_random_operations(): void
    {
        mt_srand(20261003);
        $afkir = $this->product('TELUR-AFKIR');
        $expected = ['AVAILABLE' => 0, 'RETURN_PENDING' => 0, 'AFKIR' => 0];
        $receipts = [];
        for ($i = 0; $i < 25; $i++) {
            $op = mt_rand(0, 5);
            $q = mt_rand(1, 40);
            if ($op <= 1 || $expected['AVAILABLE'] === 0) {
                $acc = mt_rand(0, $q); $ret = mt_rand(0, $q - $acc); $reg = mt_rand(0, $q - $acc - $ret); $loss = $q - $acc - $ret - $reg;
                $d = array_values(array_filter([
                    $acc ? ['type' => 'ACCEPT', 'quantity' => (string) $acc] : null,
                    $ret ? ['type' => 'RETURN_TO_SUPPLIER', 'quantity' => (string) $ret] : null,
                    $reg ? ['type' => 'REGRADE', 'quantity' => (string) $reg, 'target_product_id' => $afkir] : null,
                    $loss ? ['type' => 'LOSS', 'quantity' => (string) $loss] : null,
                ]));
                $receipts[] = [$this->receive([$this->line((string) $q, '23000', $d)]), $acc, $ret, $reg];
                $expected['AVAILABLE'] += $acc; $expected['RETURN_PENDING'] += $ret; $expected['AFKIR'] += $reg;
            } elseif ($op === 2) {
                $res = $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => (string) $q, 'reason' => 'acak']);
                if ($q <= $expected['AVAILABLE']) { $res->assertCreated(); $expected['AVAILABLE'] -= $q; } else { $res->assertStatus(409); }
            } elseif ($op === 3) {
                $res = $this->cmd('POST', 'inventory/supplier-returns', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => (string) $q, 'reason' => 'acak']);
                if ($q <= $expected['RETURN_PENDING']) { $res->assertCreated(); $expected['RETURN_PENDING'] -= $q; } else { $res->assertStatus(409); }
            } elseif ($op === 4 && $receipts !== []) {
                [$id, $acc, $ret, $reg] = $receipts[array_rand($receipts)];
                $res = $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'acak']);
                if ($res->status() === 200) {
                    $expected['AVAILABLE'] -= $acc; $expected['RETURN_PENDING'] -= $ret; $expected['AFKIR'] -= $reg;
                } else {
                    $this->assertContains($res->json('error.code'), ['INSUFFICIENT_STOCK', 'INVALID_STATUS_TRANSITION']);
                }
            } else {
                $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => (string) $q, 'direction' => 'IN', 'reason' => 'acak'])->assertCreated();
                $expected['AVAILABLE'] += $q;
            }

            $s = $this->stock();
            $this->assertEquals($expected['AVAILABLE'], (float) $s['available'], "step {$i}");
            $this->assertEquals($expected['RETURN_PENDING'], (float) $s['return_pending'], "step {$i}");
            $this->assertEquals($expected['AFKIR'], (float) $this->stock($afkir)['available'], "step {$i}");
            $this->assertSame($s['on_hand'], $s['ledger_on_hand'], "step {$i}");
        }
        $this->assertLedgerConsistent();
        $this->assertSame(0, DB::table('stock_balances')->where('quantity', '<', 0)->count());
    }

    public function test_every_product_foreign_key_outside_master_data_is_registered(): void
    {
        $fks = collect(DB::select(<<<'SQL'
            SELECT cl.relname AS tbl, a.attname AS col
            FROM pg_constraint c
            JOIN pg_class cl ON cl.oid = c.conrelid
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'products'::regclass
        SQL))->map(fn ($r) => "{$r->tbl}.{$r->col}")->sort()->values()->all();

        $registered = collect(config('dgway.product_reference_tables'))->sort()->values()->all();
        $this->assertSame($fks, $registered, 'A table that references products must be added to dgway.product_reference_tables');
    }

    public function test_product_uom_is_locked_once_stock_moved(): void
    {
        $this->receive([$this->line('5')]);
        $this->cmd('PATCH', "products/{$this->egg}", ['base_uom' => 'PCS'])->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_UOM_LOCKED');
    }

    public function test_product_classification_is_locked_once_stock_moved(): void
    {
        $this->receive([$this->line('5')]);

        $this->cmd('PATCH', "products/{$this->egg}", ['classification' => 'PACKAGING'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PRODUCT_CLASSIFICATION_LOCKED');

        $this->get_("products/{$this->egg}")
            ->assertOk()
            ->assertJsonPath('data.classification', 'SELLABLE');
    }
}
