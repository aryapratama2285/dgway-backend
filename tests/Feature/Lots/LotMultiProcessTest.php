<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Illuminate\Support\Facades\DB;
use Tests\Support\WorkerProcess;

/**
 * A5-B Part 15 / Part 23 — real concurrency with separate OS processes
 * against PostgreSQL. The worker ("phone A") holds its transaction open
 * while this process ("phone B") competes over HTTP.
 */
final class LotMultiProcessTest extends LotTestCase
{
    private function finish(WorkerProcess $w): void
    {
        $this->assertSame('DONE', $w->waitFor('DONE'));
        $this->assertSame(0, $w->finish());
    }

    /** Lot A remaining 50 (lot B 100 behind it); two 40 kg sales at once: A is never allocated twice. */
    public function test_two_concurrent_sales_never_double_allocate_the_same_lot(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '50');
        [, $lotB] = $this->receiveOn('2026-10-02', '100');
        $s1 = $this->sell(null, '40', '27000', false)->json('data');
        $s2 = $this->sell(null, '40', '27000', false)->json('data');
        $w = new WorkerProcess('hold-confirm-sale', [$this->owner->id, $s1['id'], '1000', '1080000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->cmd('POST', "sales/{$s2['id']}/confirm", ['payments' => [$this->cash('1080000')]]);
        $waited = (microtime(true) - $t) * 1000;
        $this->finish($w);

        $res->assertOk();
        $this->assertGreaterThan(500, $waited, 'B waited for A\'s position lock instead of reading stale lot balances');
        $this->assertSame([['lot_id' => $lotA, 'qty' => '40.0000']], $this->saleAllocation($s1['id']));
        $this->assertSame([['lot_id' => $lotA, 'qty' => '10.0000'], ['lot_id' => $lotB, 'qty' => '30.0000']], $this->saleAllocation($s2['id']));
        $this->assertSame(['0.0000', '70.0000'], [$this->lotQty($lotA), $this->lotQty($lotB)]);
        $this->assertLedgerConsistent();
    }

    /** Only lot A (50) exists: the second 40 kg sale fails cleanly, the lot never goes negative. */
    public function test_second_sale_fails_when_the_only_lot_is_exhausted(): void
    {
        [, $lotA] = $this->receiveOn('2026-10-01', '50');
        $s1 = $this->sell(null, '40', '27000', false)->json('data');
        $s2 = $this->sell(null, '40', '27000', false)->json('data');
        $w = new WorkerProcess('hold-confirm-sale', [$this->owner->id, $s1['id'], '800', '1080000']);
        $w->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "sales/{$s2['id']}/confirm", ['payments' => [$this->cash('1080000')]]);
        $this->finish($w);

        $res->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame(1, DB::table('payments')->count(), 'the failed sale left no payment');
        $this->assertSame('10.0000', $this->lotQty($lotA));
        $this->assertSame([], $this->saleAllocation($s2['id']));
        $this->get_("sales/{$s2['id']}")->assertJsonPath('data.status', 'DRAFT');
        $this->assertLedgerConsistent();
    }

    /** Six processes sell 15 kg at the same instant across three lots (30 + 30 + 30): 6 × 15 = 90 exactly, every lot ends at 0. */
    public function test_simultaneous_sales_across_lots_keep_every_lot_consistent(): void
    {
        $lots = [];
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $d) {
            $lots[] = $this->receiveOn($d, '30')[1];
        }
        $workers = [];
        for ($i = 0; $i < 7; $i++) {
            $w = new WorkerProcess('sale-on-go', [$this->owner->id, $this->node, $this->egg, '15', '27000']);
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
        $this->assertSame(6, $counts['RESULT ok'] ?? 0, implode(', ', $results));
        $this->assertSame(1, $counts['RESULT INSUFFICIENT_STOCK'] ?? 0, implode(', ', $results));
        $this->assertSame(['0.0000', '0.0000', '0.0000'], array_map(fn ($l) => $this->lotQty($l), $lots));
        $this->assertSame(0, DB::table('stock_lot_balances')->where('quantity', '<', 0)->count());
        $this->assertSame('90.0000', (string) DB::selectOne("SELECT sum(quantity)::numeric(18,4) AS q FROM stock_movements WHERE movement_type = 'SALE'")->q);
        $this->assertLedgerConsistent();
    }

    /** Part 23 — the same payment reversed twice at once: one reversal, one conflict, outstanding correct. */
    public function test_concurrent_reversal_of_the_same_payment(): void
    {
        $this->receiveOn('2026-10-01', '100');
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('775000')])->assertCreated()->json('data');
        $paymentId = (string) DB::table('payments')->where('sale_id', $sale['id'])->value('id');
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.outstanding', '500000.0000');

        $w = new WorkerProcess('hold-reverse-payment', [$this->owner->id, $paymentId, '1000']);
        $w->waitFor('ACQUIRED');
        $t = microtime(true);
        $res = $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'B juga membatalkan']);
        $waited = (microtime(true) - $t) * 1000;
        $this->finish($w);

        $this->assertGreaterThan(500, $waited);
        $res->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_ALREADY_REVERSED');
        $this->assertSame(1, DB::table('payment_reversals')->where('payment_id', $paymentId)->count());
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.paid', '0.0000')->assertJsonPath('data.outstanding', '1275000.0000');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.reversed')->count());
    }
}
