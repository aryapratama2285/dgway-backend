<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkerProcess;

/**
 * Real concurrency: separate OS processes against the same PostgreSQL DB.
 * The worker ("phone A") holds its transaction open; this process
 * ("phone B") competes over HTTP and must wait, then behave correctly.
 */
final class InventoryMultiProcessTest extends InventoryTestCase
{
    private function draftReceipt(string $qty = '150'): string
    {
        return $this->createReceipt([$this->line($qty, '23000', [['type' => 'ACCEPT', 'quantity' => (string) ((int) $qty - 10)], ['type' => 'LOSS', 'quantity' => '10']])])
            ->assertCreated()->json('data.id');
    }

    public function test_double_posting_of_the_same_receipt_creates_movements_once(): void
    {
        $id = $this->draftReceipt();
        $a = new WorkerProcess('hold-post-receipt', [$this->owner->id, $id, '1000']);
        $a->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->cmd('POST', "goods-receipts/{$id}/post"); // different key = a genuinely second attempt
        $waited = (microtime(true) - $t) * 1000;

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $this->assertGreaterThan(500, $waited, 'B waited for A\'s lock on the receipt');
        $res->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame(3, DB::table('stock_movements')->count());
        $this->assertSame('140.0000', $this->stock()['available']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'goods_receipt.posted')->count());
        $this->assertLedgerConsistent();
    }

    public function test_same_idempotency_key_from_two_processes_waits_then_replays(): void
    {
        $id = $this->draftReceipt();
        $key = (string) Str::uuid();
        $a = new WorkerProcess('hold-post-receipt', [$this->owner->id, $id, '1000', $key]);
        $a->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "goods-receipts/{$id}/post", [], $key);

        $this->assertSame('DONE replayed=false', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $res->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'POSTED');
        $this->assertSame(3, DB::table('stock_movements')->count());
        $this->assertSame('140.0000', $this->stock()['available']); // never 280
    }

    public function test_concurrent_decrement_waits_and_never_goes_negative(): void
    {
        $this->receive([$this->line('5')]);
        $a = new WorkerProcess('hold-loss', [$this->owner->id, $this->node, $this->egg, '4', '1000']);
        $a->waitFor('ACQUIRED');

        // B read "5 available" before A commits, but must not be able to use it
        $res = $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '4', 'reason' => 'race']);

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $res->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame('1.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }

    public function test_many_processes_racing_for_the_last_units(): void
    {
        $this->receive([$this->line('4')]);
        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $w = new WorkerProcess('loss-on-go', [$this->owner->id, $this->node, $this->egg, '1']);
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
        $this->assertSame(4, DB::table('inventory_operations')->count());
        $this->assertLedgerConsistent();
    }

    public function test_concurrent_reversal_happens_once(): void
    {
        $id = $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])]);
        $a = new WorkerProcess('hold-reverse-receipt', [$this->owner->id, $id, '800']);
        $a->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "goods-receipts/{$id}/reverse", ['reason' => 'B juga']);

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $res->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(3, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->assertSame('0.0000', $this->stock()['available']);
    }

    /**
     * Two different receipts for the same position posted at the same time.
     * No document-number lock is shared here, so only the balance row lock
     * prevents a lost update on the projection (140 instead of 280).
     */
    public function test_concurrent_postings_to_the_same_position_do_not_lose_updates(): void
    {
        // positions already exist, so nothing but the row lock can serialise the two postings
        $this->receive([$this->line('20', '23000', [['type' => 'ACCEPT', 'quantity' => '10'], ['type' => 'LOSS', 'quantity' => '10']])]);
        $gr1 = $this->draftReceipt();
        $gr2 = $this->draftReceipt();
        $a = new WorkerProcess('hold-post-receipt', [$this->owner->id, $gr1, '1000']);
        $a->waitFor('ACQUIRED');

        $this->cmd('POST', "goods-receipts/{$gr2}/post")->assertOk();

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $this->assertSame('290.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }

    /** A reversal (no document number) racing a loss for the same stock. */
    public function test_reversal_racing_a_loss_cannot_overdraw(): void
    {
        $gr = $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])]);
        $a = new WorkerProcess('hold-loss', [$this->owner->id, $this->node, $this->egg, '100', '1000']);
        $a->waitFor('ACQUIRED');

        $res = $this->cmd('POST', "goods-receipts/{$gr}/reverse", ['reason' => 'race']);

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $res->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame('40.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }

    /** A3 §38 D: a rebuild started while a posting is in flight waits for it, then includes it. */
    public function test_rebuild_waits_for_an_in_flight_posting(): void
    {
        $this->receive([$this->line('10')]);
        $a = new WorkerProcess('hold-loss', [$this->owner->id, $this->node, $this->egg, '4', '1000']);
        $a->waitFor('ACQUIRED');

        $t = microtime(true);
        $written = $this->app->make(\App\Application\Inventory\StockProjection::class)->rebuild();
        $waited = (microtime(true) - $t) * 1000;

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $this->assertGreaterThan(500, $waited, 'rebuild waited for the posting to commit');
        $this->assertGreaterThanOrEqual(1, $written);
        $this->assertSame('6.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }

    /** A3 §38 D: a posting that arrives during a rebuild waits for it, then applies on top. */
    public function test_posting_waits_for_an_in_flight_rebuild(): void
    {
        $this->receive([$this->line('10')]);
        $a = new WorkerProcess('hold-rebuild', ['1000']);
        $a->waitFor('ACQUIRED');

        $t = microtime(true);
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '3', 'reason' => 'during rebuild'])->assertCreated();
        $waited = (microtime(true) - $t) * 1000;

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $this->assertGreaterThan(500, $waited, 'posting waited for the rebuild to commit');
        $this->assertSame('7.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }
}
