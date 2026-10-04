<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;
use Tests\Support\WorkerProcess;

/**
 * A4 §30 — real concurrency with separate OS processes against PostgreSQL.
 * The worker ("phone A") holds its transaction open; this process
 * ("phone B") competes over HTTP and must wait, then fail cleanly.
 */
final class SalesMultiProcessTest extends SalesTestCase
{
    private function finish(WorkerProcess $a): void
    {
        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
    }

    /** Scenario 1 — stock 20, sale A 15 and sale B 15 at the same time: only one succeeds. */
    public function test_stock_race_only_one_sale_succeeds(): void
    {
        $this->stockUp('20');
        $a = $this->sell(null, '15', '26000', false)->json('data');
        $b = $this->sell(null, '15', '26000', false)->json('data');
        $w = new WorkerProcess('hold-confirm-sale', [$this->owner->id, $a['id'], '1000', '390000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->cmd('POST', "sales/{$b['id']}/confirm", ['payments' => [$this->cash('390000')]]);
        $waited = (microtime(true) - $t) * 1000;
        $this->finish($w);

        $this->assertGreaterThan(500, $waited);
        $res->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame('5.0000', $this->stock()['available']);
        $this->get_("sales/{$b['id']}")->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame(1, DB::table('payments')->count(), 'the failed sale left no payment');
        $this->assertLedgerConsistent();
    }

    /** Scenario 1b — six processes start selling 5 kg at the same instant from 20 kg. */
    public function test_many_simultaneous_sales_never_oversell(): void
    {
        $this->stockUp('20');
        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $w = new WorkerProcess('sale-on-go', [$this->owner->id, $this->node, $this->egg, '5', '26000']);
            $w->waitFor('READY');
            $workers[] = $w;
        }
        foreach ($workers as $w) {
            $w->send('go');
        }
        $results = array_map(fn (WorkerProcess $w) => $w->waitFor('RESULT', 30), $workers);
        foreach ($workers as $w) {
            $w->finish();
        }
        $counts = array_count_values($results);
        $this->assertSame(4, $counts['RESULT ok'] ?? 0, implode(', ', $results));
        $this->assertSame(2, $counts['RESULT INSUFFICIENT_STOCK'] ?? 0, implode(', ', $results));
        $this->assertSame('0.0000', $this->stock()['available']);
        $this->assertSame(4, DB::table('sales')->where('status', 'COMPLETED')->count());
        $this->assertSame(4, DB::table('payments')->count());
        $this->assertLedgerConsistent();
    }

    /** Scenario 2 — outstanding 1 000 000, payment A 700 000 and B 700 000: no overpayment. */
    public function test_payment_race_cannot_overpay(): void
    {
        $this->stockUp();
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '40', '25000', true)->json('data');
        $w = new WorkerProcess('hold-payment', [$this->owner->id, $sale['id'], '700000', '1000']);
        $w->waitFor('ACQUIRED');

        $res = $this->pay($sale['id'], '700000');
        $this->finish($w);

        $res->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_OUTSTANDING')->assertJsonPath('error.details.outstanding', '300000.0000');
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.paid', '700000.0000')->assertJsonPath('data.outstanding', '300000.0000');
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** Scenario 3 — remaining credit 1 000 000, credit sales A 700 000 and B 700 000: not both. */
    public function test_credit_limit_race_only_one_credit_sale_passes(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-ABC', 14, '1000000');
        $a = $this->sell($abc, '28', '25000', false)->json('data');
        $b = $this->sell($abc, '28', '25000', false)->json('data');
        $w = new WorkerProcess('hold-confirm-sale', [$this->owner->id, $a['id'], '1000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->cmd('POST', "sales/{$b['id']}/confirm");
        $waited = (microtime(true) - $t) * 1000;
        $this->finish($w);

        $this->assertGreaterThan(500, $waited, 'B waited on the customer credit lock');
        $res->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED')->assertJsonPath('error.details.existing_outstanding', '700000.0000');
        $this->get_('receivables/customers')->assertJsonPath('data.0.outstanding', '700000.0000');
        $this->assertSame('72.0000', $this->stock()['available']);
    }

    /** Scenario 4 — the same sale confirmed twice at once: one inventory posting. */
    public function test_double_confirmation_posts_inventory_once(): void
    {
        $this->stockUp('100');
        $sale = $this->sell(null, '30', '26000', false)->json('data');
        $w = new WorkerProcess('hold-confirm-sale', [$this->owner->id, $sale['id'], '1000', '780000']);
        $w->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "sales/{$sale['id']}/confirm", ['payments' => [$this->cash('780000')]]);
        $this->finish($w);

        $res->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame('70.0000', $this->stock()['available']);
    }

    /** Scenario 5 — the same sale reversed twice at once: one reversal. */
    public function test_double_reversal_only_one_succeeds(): void
    {
        $this->stockUp('100');
        $sale = $this->sell(null, '30', '26000', true, [$this->cash('780000')])->json('data');
        $w = new WorkerProcess('hold-reverse-sale', [$this->owner->id, $sale['id'], '1000']);
        $w->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'B juga', 'reverse_payments' => true]);
        $this->finish($w);

        $res->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->assertSame(1, DB::table('payment_reversals')->count());
        $this->assertSame('100.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }
}
