<?php

declare(strict_types=1);

namespace Tests\Feature\DataEvolution;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

/** A5 addendum §7, §10, §11 — reconciliation fingerprint, trace and schema catalog. */
final class MigrationToolingTest extends SalesTestCase
{
    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockUp('150');
        $abc = $this->customer('CUS-ABC', 7);
        $this->sale = $this->sell($abc, '100', '25000', true, [$this->cash('1500000', 'BANK_TRANSFER')])->json('data');
        $this->pay($this->sale['id'], '700000', 'QRIS')->assertCreated();
        $this->cmd('POST', "sales/{$this->sale['id']}/returns", ['reason' => 'pecah', 'lines' => [['sale_line_id' => $this->sale['lines'][0]['id'], 'quantity' => '2', 'disposition' => 'LOSS']]])->assertCreated();
        $this->sell(null, '10', '26000', true, [$this->cash('260000')])->assertCreated();
    }

    private function fingerprint(array $args = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'fp');
        $this->assertSame(0, Artisan::call('dgway:data:fingerprint', ['--output' => $file] + $args));

        return [$file, json_decode((string) file_get_contents($file), true)];
    }

    public function test_fingerprint_is_deterministic_and_reconciles_with_itself(): void
    {
        [$a, $fa] = $this->fingerprint();
        [, $fb] = $this->fingerprint();
        $this->assertSame($fa, $fb);
        $this->assertSame(0, Artisan::call('dgway:data:fingerprint', ['--compare' => $a]));
        $this->assertStringContainsString('identical', Artisan::output());

        // rebuilding a projection is not a change of history
        Artisan::call('dgway:inventory:rebuild-balances', ['--force' => true]);
        $this->assertSame(0, Artisan::call('dgway:data:fingerprint', ['--compare' => $a]));
    }

    public function test_invariants_carry_the_economic_meaning(): void
    {
        [, $f] = $this->fingerprint(['--only' => 'invariants']);
        $inv = $f['invariants'];
        $summary = $this->get_('receivables/summary')->json('data');
        $this->assertSame($summary['total'], $inv['receivable']['outstanding']);
        $this->assertSame($inv['receivable']['outstanding'], $inv['receivable']['recomputed']);
        $this->assertSame('250000.0000', $inv['receivable']['outstanding']); // 2.5M − 1.5M − 0.7M − 50k credit
        $this->assertSame('2460000.0000', $inv['payments']['valid']);
        $this->assertSame('50000.0000', $inv['customer_returns']['credit']);
        $stock = collect($inv['stock_by_position'])->firstWhere('bucket', 'AVAILABLE');
        $this->assertSame($this->stock()['available'], $stock['quantity']);
        $this->assertArrayNotHasKey('checksums', $f);
    }

    public function test_a_business_change_is_reported_by_compare(): void
    {
        [$before] = $this->fingerprint();
        $this->pay($this->sale['id'], '1000')->assertCreated();
        $this->assertSame(1, Artisan::call('dgway:data:fingerprint', ['--compare' => $before]));
        $out = Artisan::output();
        $this->assertStringContainsString('invariants.payments.valid', $out);
        $this->assertStringContainsString('checksums.payments.md5', $out);
        $this->assertStringContainsString('invariants.receivable.outstanding: "250000.0000" → "249000.0000"', $out);
    }

    /** What a careless migration script that rewrites history would look like — and how it is caught. */
    public function test_a_migration_that_alters_history_is_detected(): void
    {
        [$before] = $this->fingerprint();
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_append_only');
            DB::table('stock_movements')->where('movement_type', 'SALE')->limit(1)->update(['reason' => 'rewritten by migration']);
            $this->assertSame(1, Artisan::call('dgway:data:fingerprint', ['--compare' => $before]));
            $out = Artisan::output();
            $this->assertStringContainsString('checksums.stock_movements.md5', $out);
            $this->assertStringNotContainsString('stock_by_position', $out, 'quantities untouched → only the strong checksum notices');
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, Artisan::call('dgway:data:fingerprint', ['--compare' => $before]));
    }

    public function test_trace_follows_a_sale_to_money_stock_actor_and_audit(): void
    {
        $this->assertSame(0, Artisan::call('dgway:trace', ['reference' => $this->sale['number'], '--json' => true]));
        $t = json_decode(Artisan::output(), true);
        $this->assertSame('sale', $t['root']['type']);
        $types = array_count_values(array_column($t['documents'], 'type'));
        $this->assertSame(['sale' => 1, 'payment' => 2, 'customer_return' => 1], $types);
        $this->assertSame(['SALE', 'CUSTOMER_RETURN', 'LOSS'], array_column($t['stock_movements'], 'movement_type'));
        $this->assertContains($this->sale['id'], $t['correlation_ids']);
        $this->assertSame($this->owner->id, $t['actors'][0]['id']);
        $actions = array_column($t['audit'], 'action');
        foreach (['sale.created', 'sale.confirmed', 'inventory.sale', 'payment.recorded', 'customer_return.recorded'] as $a) {
            $this->assertContains($a, $actions);
        }

        // any identifier leads to the same transaction
        $paymentId = DB::table('payments')->where('sale_id', $this->sale['id'])->value('id');
        $movementId = DB::table('stock_movements')->where('movement_type', 'SALE')->where('source_document_id', $this->sale['id'])->value('id');
        foreach ([$paymentId, $movementId, strtolower($this->sale['number'])] as $ref) {
            Artisan::call('dgway:trace', ['reference' => $ref, '--json' => true]);
            $this->assertContains($this->sale['id'], array_column(json_decode(Artisan::output(), true)['documents'], 'id'), (string) $ref);
        }
        $this->assertSame(1, Artisan::call('dgway:trace', ['reference' => 'SAL-000000-999']));
        $this->assertSame(0, Artisan::call('dgway:trace', ['reference' => DB::table('goods_receipts')->value('number')]));
    }

    public function test_schema_catalog_document_matches_the_live_schema(): void
    {
        $this->assertSame(0, Artisan::call('dgway:schema:catalog', ['--check' => true]), 'docs/SCHEMA_CATALOG.md is stale: run php artisan dgway:schema:catalog');
    }
}
