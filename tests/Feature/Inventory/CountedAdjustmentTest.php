<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\WorkerProcess;

/**
 * F3 — auditable stock correction. Counted mode (stock opname): the server
 * locks the position, reads the system quantity and posts the difference.
 * The delta mode of A3 stays backward compatible. Both need inventory.adjust.
 */
final class CountedAdjustmentTest extends InventoryTestCase
{
    /** @param array<string, mixed> $body */
    private function adjust(array $body, ?string $key = null): TestResponse
    {
        return $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'reason' => 'stock opname', ...$body], $key);
    }

    public function test_counted_below_system_posts_an_adjustment_out_fifo(): void
    {
        $this->receive([$this->line('15', '25000')]);
        $op = $this->adjust(['counted_quantity' => '14.8', 'expected_available' => '15', 'reason_code' => 'COUNT_CORRECTION'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'ADJUSTMENT')
            ->assertJsonPath('data.direction', 'OUT')
            ->assertJsonPath('data.quantity', '0.2000')
            ->assertJsonPath('data.counted_quantity', '14.8000')
            ->assertJsonPath('data.system_quantity', '15.0000')
            ->assertJsonPath('data.reason_code', 'COUNT_CORRECTION')
            ->json('data');

        $this->assertSame('14.8000', $this->stock()['available'], 'Example 4: 15 → 14.8 via a −0.2 movement, never an overwrite');
        $m = DB::table('stock_movements')->where('source_document_id', $op['id'])->sole();
        $this->assertSame(['ADJUSTMENT_OUT', '0.2000', -1], [$m->movement_type, (string) $m->quantity, (int) $m->direction]);
        $this->assertNotNull($m->lot_id, 'taken from the existing lot (FIFO)');
        $this->assertSame(['counted_quantity' => '14.8000', 'system_quantity' => '15.0000'], array_map('strval', (array) DB::table('inventory_operations')->where('id', $op['id'])->first(['counted_quantity', 'system_quantity'])));
        $this->assertLedgerConsistent();
    }

    public function test_counted_above_system_posts_an_adjustment_in_with_unknown_cost(): void
    {
        $this->receive([$this->line('10', '25000')]);
        $op = $this->adjust(['counted_quantity' => '12.5', 'reason_code' => 'FOUND_STOCK'])->assertCreated()
            ->assertJsonPath('data.direction', 'IN')->assertJsonPath('data.quantity', '2.5000')->json('data');

        $this->assertSame('12.5000', $this->stock()['available']);
        $lot = DB::table('inventory_lots')->where('source_document_id', $op['id'])->sole();
        $this->assertSame('ADJUSTMENT', $lot->origin);
        $this->assertNull($lot->goods_receipt_line_id, 'no purchase cost: cost basis unknown, never invented');
        $this->assertNull(DB::table('stock_movements')->where('source_document_id', $op['id'])->value('unit_cost'));
        $this->assertLedgerConsistent();
    }

    public function test_counted_equal_to_system_or_changed_stock_writes_nothing(): void
    {
        $this->receive([$this->line('10', '25000')]);
        $this->adjust(['counted_quantity' => '10'])->assertStatus(422)->assertJsonPath('error.code', 'ADJUSTMENT_NOT_REQUIRED')
            ->assertJsonPath('error.details.system_quantity', '10.0000');
        $this->adjust(['counted_quantity' => '9', 'expected_available' => '11'])->assertStatus(409)->assertJsonPath('error.code', 'STOCK_CHANGED_SINCE_COUNT')
            ->assertJsonPath('error.details.current_available', '10.0000')->assertJsonPath('error.details.expected_available', '11.0000');
        $this->assertSame(0, DB::table('inventory_operations')->where('operation_type', 'ADJUSTMENT')->count());
        $this->assertSame('10.0000', $this->stock()['available']);
    }

    public function test_counted_mode_cannot_create_negative_stock_or_mix_modes(): void
    {
        $this->receive([$this->line('5', '25000')]);
        $this->adjust(['counted_quantity' => '-1'])->assertStatus(422);
        $this->adjust(['counted_quantity' => '0'])->assertCreated()->assertJsonPath('data.quantity', '5.0000')->assertJsonPath('data.direction', 'OUT');
        $this->assertSame('0.0000', $this->stock()['available'], 'counting zero empties the position, never below');
        $this->adjust(['counted_quantity' => '1', 'quantity' => '1', 'direction' => 'IN'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->adjust(['counted_quantity' => '1.12345'])->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_PRECISION_EXCEEDED');
        $this->adjust(['counted_quantity' => '1', 'reason_code' => 'DAMAGE'])->assertStatus(422); // damage = LOSS
        $this->assertLedgerConsistent();
    }

    public function test_delta_mode_is_backward_compatible_and_gains_an_optional_reason_code(): void
    {
        $this->receive([$this->line('10', '25000')]);
        $this->adjust(['quantity' => '2', 'direction' => 'IN'])->assertCreated()->assertJsonPath('data.reason_code', null)->assertJsonPath('data.counted_quantity', null);
        $this->adjust(['quantity' => '1', 'direction' => 'OUT', 'reason_code' => 'SHRINKAGE'])->assertCreated()->assertJsonPath('data.reason_code', 'SHRINKAGE');
        $this->adjust(['quantity' => '50', 'direction' => 'OUT'])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame('11.0000', $this->stock()['available']);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'inventory.adjustment')->count());
    }

    public function test_adjustment_replay_is_idempotent_in_both_modes(): void
    {
        $this->receive([$this->line('15', '25000')]);
        $key = (string) Str::uuid();
        $first = $this->adjust(['counted_quantity' => '14.8'], $key)->assertCreated()->json('data');
        // the stock is now 14.8: re-executing would be ADJUSTMENT_NOT_REQUIRED — a replay must return the original instead
        $this->adjust(['counted_quantity' => '14.8'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first['id']);
        $this->assertSame('14.8000', $this->stock()['available']);
        $this->assertSame(1, DB::table('inventory_operations')->where('operation_type', 'ADJUSTMENT')->count());

        $deltaKey = (string) Str::uuid();
        $this->adjust(['quantity' => '1', 'direction' => 'OUT'], $deltaKey)->assertCreated();
        $this->adjust(['quantity' => '1', 'direction' => 'OUT'], $deltaKey)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame('13.8000', $this->stock()['available']);
    }

    public function test_adjustment_needs_the_inventory_adjust_permission_consumption_does_not(): void
    {
        $this->receive([$this->line('10', '25000')]);
        config(['dgway.authorization.role_permissions.OWNER' => []]); // a future role without inventory.adjust
        $this->adjust(['counted_quantity' => '9'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->adjust(['quantity' => '1', 'direction' => 'IN'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertSame(0, DB::table('inventory_operations')->count());

        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason_code' => 'SAMPLE'])->assertCreated();
        // F5A (decision D1): a loss is a stock correction too — it needs inventory.correct
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'pecah'])
            ->assertStatus(403)->assertJsonPath('error.details.permission', 'inventory.correct');
        $this->assertSame('9.0000', $this->stock()['available']);
    }

    public function test_adjustments_are_audited_and_visible_in_history_and_activity(): void
    {
        $this->receive([$this->line('15', '25000')]);
        $op = $this->adjust(['counted_quantity' => '14.8', 'reason_code' => 'COUNT_CORRECTION'])->assertCreated()->json('data');

        $after = json_decode((string) DB::table('audit_logs')->where('action', 'inventory.adjustment')->sole()->after, true);
        $this->assertSame(['14.8000', '15.0000', 'COUNT_CORRECTION'], [$after['counted_quantity'], $after['system_quantity'], $after['reason_code']]);
        $this->get_("inventory/operations/{$op['id']}")->assertOk()->assertJsonPath('data.counted_quantity', '14.8000')
            ->assertJsonPath('data.movements.0.movement_type', 'ADJUSTMENT_OUT');
        $this->get_('activity?date=2026-10-03&type=INVENTORY_ADJUSTMENT')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.detail', 'OUT COUNT_CORRECTION')->assertJsonPath('data.0.document.number', $op['number']);
    }

    /** Real concurrency: a counted adjustment waits for a concurrent consumption, then counts against the NEW figure. */
    public function test_counted_adjustment_racing_a_consumption_uses_the_locked_quantity(): void
    {
        $this->receive([$this->line('10', '25000')]);
        $a = new WorkerProcess('hold-consume', [$this->owner->id, $this->node, $this->egg, '3', '1000']);
        $a->waitFor('ACQUIRED');

        // the operator saw 10 and counted 9 — but 3 are being consumed right now
        $res = $this->adjust(['counted_quantity' => '9', 'expected_available' => '10']);

        $this->assertSame('DONE', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());
        $res->assertStatus(409)->assertJsonPath('error.code', 'STOCK_CHANGED_SINCE_COUNT')->assertJsonPath('error.details.current_available', '7.0000');
        $this->assertSame('7.0000', $this->stock()['available']);

        // without expected_available the count is applied against the locked figure: 7 → 9 (+2)
        $b = new WorkerProcess('hold-consume', [$this->owner->id, $this->node, $this->egg, '1', '800']);
        $b->waitFor('ACQUIRED');
        $res2 = $this->adjust(['counted_quantity' => '9']);
        $this->assertSame('DONE', $b->waitFor('DONE'));
        $this->assertSame(0, $b->finish());
        $res2->assertCreated()->assertJsonPath('data.system_quantity', '6.0000')->assertJsonPath('data.quantity', '3.0000')->assertJsonPath('data.direction', 'IN');
        $this->assertSame('9.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }
}
