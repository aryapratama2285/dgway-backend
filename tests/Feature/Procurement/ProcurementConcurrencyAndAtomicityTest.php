<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkerProcess;

/**
 * F4A §7–§9 — real separate processes and an injected failure:
 *   - two receipts racing for the last open quantity cannot over-receive;
 *   - a post racing a close sees the CLOSED order;
 *   - a retried post (same Idempotency-Key) replays, never duplicates;
 *   - a failure in the middle of posting leaves no business effect at all.
 */
final class ProcurementConcurrencyAndAtomicityTest extends ProcurementTestCase
{
    public function test_two_concurrent_receipts_cannot_over_receive(): void
    {
        $po = $this->confirmedPo([['product_id' => $this->egg, 'quantity' => '20', 'unit_cost' => '23500']]);
        $a = $this->createReceipt([$this->poLine($po, '15')], $po['id'])->assertCreated()->json('data.id');
        $b = $this->createReceipt([$this->poLine($po, '15')], $po['id'])->assertCreated()->json('data.id'); // both fit at draft time

        $worker = new WorkerProcess('hold-post-receipt', [$this->owner->id, $a, '1000']);
        $worker->waitFor('ACQUIRED');
        $t = microtime(true);
        $res = $this->cmd('POST', "goods-receipts/{$b}/post");
        $waited = (microtime(true) - $t) * 1000;
        $this->assertSame('DONE', $worker->waitFor('DONE'));
        $this->assertSame(0, $worker->finish());

        $this->assertGreaterThan(500, $waited, 'B waited for the purchase order lock held by A');
        $res->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        $this->assertSame('15.0000', $this->po($po['id'])['lines'][0]['received_quantity']);
        $this->assertSame('15.0000', $this->stock()['available']);
        $this->assertSame('DRAFT', DB::table('goods_receipts')->where('id', $b)->value('status'));
        $this->assertSame(1, DB::table('inventory_lots')->count());
        $this->assertLedgerConsistent();
    }

    public function test_post_racing_a_close_sees_the_closed_order(): void
    {
        $po = $this->confirmedPo();
        $this->receivePo($po, [$this->poLine($po, '60')]);
        $draft = $this->createReceipt([$this->poLine($po, '30')], $po['id'])->assertCreated()->json('data.id');

        $worker = new WorkerProcess('hold-close-po', [$this->owner->id, $po['id'], '800']);
        $worker->waitFor('ACQUIRED');
        $res = $this->cmd('POST', "goods-receipts/{$draft}/post");
        $this->assertSame('DONE', $worker->waitFor('DONE'));
        $this->assertSame(0, $worker->finish());

        $res->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $po = $this->po($po['id']);
        $this->assertSame(['CLOSED', '60.0000', '40.0000'], [$po['status'], $po['lines'][0]['received_quantity'], $po['lines'][0]['unfulfilled_quantity']]);
        $this->assertSame('60.0000', $this->stock()['available']);
    }

    public function test_retried_post_with_the_same_key_replays_and_duplicates_nothing(): void
    {
        $po = $this->confirmedPo();
        $id = $this->createReceipt([$this->poLine($po, '60')], $po['id'])->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $first = $this->cmd('POST', "goods-receipts/{$id}/post", [], $key)->assertOk()->json('data');
        $this->cmd('POST', "goods-receipts/{$id}/post", [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.lines.0.lot.id', $first['lines'][0]['lot']['id']);
        $this->assertSame([1, 1, 1], [
            DB::table('goods_receipt_lines')->where('goods_receipt_id', $id)->count(),
            DB::table('inventory_lots')->where('goods_receipt_id', $id)->count(),
            DB::table('stock_movements')->where('source_document_id', $id)->count(),
        ]);
        $this->assertSame('60.0000', $this->stock()['available']);
        $this->assertSame('60.0000', $this->po($po['id'])['lines'][0]['received_quantity']);
    }

    public function test_failure_in_the_middle_of_posting_leaves_no_partial_effects(): void
    {
        $po = $this->confirmedPo();
        $id = $this->createReceipt([$this->poLine($po, '60')], $po['id'])->assertCreated()->json('data.id');
        $before = $this->po($po['id']);

        // inject a failure AFTER the PO, the lot and the first movement were written
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION f4a_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'injected failure after the movement was written'; END; $$;
            CREATE TRIGGER f4a_test_fail AFTER INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION f4a_test_fail();
        SQL);
        try {
            $this->cmd('POST', "goods-receipts/{$id}/post")->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS f4a_test_fail ON stock_movements; DROP FUNCTION IF EXISTS f4a_test_fail();');
        }

        $this->assertSame('DRAFT', DB::table('goods_receipts')->where('id', $id)->value('status'));
        $this->assertSame([0, 0, 0], [DB::table('inventory_lots')->count(), DB::table('stock_movements')->count(), DB::table('stock_balances')->count()]);
        $after = $this->po($po['id']);
        $this->assertSame([$before['status'], $before['version'], '0.0000'], [$after['status'], $after['version'], $after['lines'][0]['received_quantity']]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'goods_receipt.posted')->count());

        // the same receipt posts normally afterwards
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk();
        $this->assertSame('60.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }
}
