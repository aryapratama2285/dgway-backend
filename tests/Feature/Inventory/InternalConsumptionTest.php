<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * F3 — INTERNAL_CONSUMPTION: DG Way uses its own material (packaging, labels,
 * tape …). Its own operation AND movement type, never LOSS / ADJUSTMENT_OUT.
 */
final class InternalConsumptionTest extends InventoryTestCase
{
    private string $plastic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plastic = $this->cmd('POST', 'products', [
            'sku' => 'PLASTIK-15', 'name' => 'Plastik kemasan 15x30', 'category_id' => $this->category('PACK'), 'base_uom' => 'PCS', 'classification' => 'PACKAGING',
        ])->assertCreated()->assertJsonPath('data.classification', 'PACKAGING')->json('data.id');
    }

    /** Receives [$qty] of [$product] as its own lot (one goods receipt = one lot). */
    private function receiveLot(string $qty, string $cost, ?string $product = null): string
    {
        return $this->receive([$this->line($qty, $cost, [], $product ?? $this->plastic)]);
    }

    /** @param array<string, mixed> $extra */
    private function consume(string $qty, string $reason = 'PACKING', array $extra = [], ?string $key = null, ?string $product = null): TestResponse
    {
        return $this->cmd('POST', 'inventory/consumptions', [
            'node_id' => $this->node, 'product_id' => $product ?? $this->plastic, 'quantity' => $qty, 'reason_code' => $reason, ...$extra,
        ], $key);
    }

    public function test_consumption_decreases_available_stock_through_its_own_movement_type(): void
    {
        $this->receiveLot('100', '150');

        $op = $this->consume('6', 'PACKING', ['notes' => 'kemas pesanan retail', 'reference_text' => 'ORDER-WA-0042'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'INTERNAL_CONSUMPTION')
            ->assertJsonPath('data.quantity', '6.0000')
            ->assertJsonPath('data.uom', 'PCS')
            ->assertJsonPath('data.reason_code', 'PACKING')
            ->assertJsonPath('data.reason', 'kemas pesanan retail')
            ->assertJsonPath('data.reference_text', 'ORDER-WA-0042')
            ->assertJsonPath('data.direction', null)
            ->json('data');
        $this->assertMatchesRegularExpression('/^INV-261003-\d{3}$/', $op['number']);

        $this->assertSame('94.0000', $this->stock($this->plastic)['available'], 'Example 1: 100 − 6 = 94');
        $movement = DB::table('stock_movements')->where('source_document_id', $op['id'])->sole();
        $this->assertSame('INTERNAL_CONSUMPTION', $movement->movement_type);
        $this->assertSame(-1, (int) $movement->direction);
        $this->assertSame('AVAILABLE', $movement->bucket);
        $this->assertSame('PACKING', DB::table('inventory_operations')->where('id', $op['id'])->value('reason_code'));
        $this->assertSame(0, DB::table('stock_movements')->whereIn('movement_type', ['LOSS', 'ADJUSTMENT_OUT'])->count(), 'never a loss or an adjustment');
        $this->assertLedgerConsistent();
    }

    public function test_balance_equals_the_ledger_and_lots_are_consumed_fifo(): void
    {
        $lot1 = DB::table('inventory_lots')->where('goods_receipt_id', $this->receiveLot('10', '150'))->value('id');
        $this->clock->travelTo('2026-10-03T03:00:00Z');
        $lot2 = DB::table('inventory_lots')->where('goods_receipt_id', $this->receiveLot('10', '160'))->value('id');

        $op = $this->consume('14', 'DELIVERY')->assertCreated()->json('data');

        $byLot = DB::table('stock_movements')->where('source_document_id', $op['id'])->orderBy('sequence')->pluck('quantity', 'lot_id')->all();
        $this->assertSame([$lot1 => '10.0000', $lot2 => '4.0000'], $byLot, 'oldest lot first');
        $this->assertSame('0.0000', (string) DB::table('stock_lot_balances')->where('lot_id', $lot1)->where('bucket', 'AVAILABLE')->value('quantity'));
        $this->assertSame('6.0000', (string) DB::table('stock_lot_balances')->where('lot_id', $lot2)->where('bucket', 'AVAILABLE')->value('quantity'));
        $ledger = (string) DB::table('stock_movements')->where('product_id', $this->plastic)->selectRaw('sum(signed_quantity)::numeric(18,4) AS q')->value('q');
        $this->assertSame('6.0000', $ledger);
        $this->assertSame('6.0000', $this->stock($this->plastic)['available'], 'projection = ledger');

        // the operation detail lists exactly the FIFO lots used
        $detail = $this->get_("inventory/operations/{$op['id']}")->assertOk()->json('data');
        $this->assertSame([$lot1, $lot2], array_column(array_column($detail['movements'], 'lot'), 'id'));
        $this->assertSame(['INTERNAL_CONSUMPTION', 'INTERNAL_CONSUMPTION'], array_column($detail['movements'], 'movement_type'));
        $this->assertLedgerConsistent();
    }

    public function test_costing_traceability_consumption_to_lot_to_receipt_line_cost(): void
    {
        $this->receiveLot('10', '150');
        $op = $this->consume('4', 'LABELING')->assertCreated()->json('data');

        // consumption movement → lot → goods receipt line → unit cost (a pure join; nothing copied, nothing invented)
        $trace = DB::selectOne(<<<'SQL'
            SELECT m.quantity, gl.unit_cost, g.currency
              FROM stock_movements m
              JOIN inventory_lots l ON l.id = m.lot_id
              JOIN goods_receipt_lines gl ON gl.id = l.goods_receipt_line_id
              JOIN goods_receipts g ON g.id = gl.goods_receipt_id
             WHERE m.source_document_id = ?
        SQL, [$op['id']]);
        $this->assertSame(['4.0000', '150.0000', 'IDR'], [(string) $trace->quantity, (string) $trace->unit_cost, $trace->currency]);
        $this->assertNull(DB::table('stock_movements')->where('source_document_id', $op['id'])->value('unit_cost'), 'cost is not copied onto the consumption');
    }

    public function test_quantity_precision_and_base_uom_are_enforced(): void
    {
        $this->receiveLot('100', '150');
        $this->consume('1.5')->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_PRECISION_EXCEEDED'); // PCS = whole units
        $this->consume('2', 'PACKING', ['uom' => 'KG'])->assertStatus(422)->assertJsonPath('error.code', 'UOM_NOT_BASE');
        $this->consume('0')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // KG product: 3 decimals allowed, 4 rejected
        $this->receive([$this->line('75', '25000')]);
        $this->consume('0.125', 'SAMPLE', [], null, $this->egg)->assertCreated()->assertJsonPath('data.quantity', '0.1250');
        $this->consume('0.1255', 'SAMPLE', [], null, $this->egg)->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_PRECISION_EXCEEDED');
        $this->assertSame('74.8750', $this->stock()['available']);
        $this->assertSame('100.0000', $this->stock($this->plastic)['available']);
    }

    public function test_over_consumption_is_rejected_and_writes_nothing(): void
    {
        $this->receiveLot('5', '150');
        $this->consume('6')->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        $this->assertSame('5.0000', $this->stock($this->plastic)['available']);
        $this->assertSame(0, DB::table('inventory_operations')->where('operation_type', 'INTERNAL_CONSUMPTION')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'INTERNAL_CONSUMPTION')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'inventory.internal_consumption')->count());
    }

    public function test_duplicate_idempotency_key_never_consumes_twice(): void
    {
        $this->receiveLot('500', '20');
        $key = (string) Str::uuid();
        $first = $this->consume('25', 'LABELING', [], $key)->assertCreated()->json('data');
        $replay = $this->consume('25', 'LABELING', [], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->json('data');

        $this->assertSame($first, $replay, 'the original response, byte for byte');
        $this->assertSame('475.0000', $this->stock($this->plastic)['available'], 'Example 2: 500 − 25 = 475, once');
        $this->assertSame(1, DB::table('inventory_operations')->where('operation_type', 'INTERNAL_CONSUMPTION')->count());

        $this->consume('26', 'LABELING', [], $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('475.0000', $this->stock($this->plastic)['available']);
    }

    public function test_invalid_product_node_and_reason_are_rejected(): void
    {
        $this->receiveLot('10', '150');
        $this->consume('1', 'PACKING', [], null, (string) Str::uuid())->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->cmd('POST', "products/{$this->plastic}/deactivate")->assertOk();
        $this->consume('1')->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
        $this->cmd('POST', "products/{$this->plastic}/activate")->assertOk();

        $office = $this->node('KANTOR', ['FULFILLMENT']); // no INVENTORY capability
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $office, 'product_id' => $this->plastic, 'quantity' => '1', 'reason_code' => 'ADMIN'])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => (string) Str::uuid(), 'product_id' => $this->plastic, 'quantity' => '1', 'reason_code' => 'ADMIN'])
            ->assertStatus(404);

        $this->consume('1', 'DAMAGE')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED'); // damage = LOSS, not consumption
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $this->node, 'product_id' => $this->plastic, 'quantity' => '1'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['reason_code']]]]);
        $this->consume('1', 'PACKING', ['reference_text' => str_repeat('x', 61)])->assertStatus(422);
        $this->assertSame('10.0000', $this->stock($this->plastic)['available']);
    }

    public function test_consumption_is_audited_listed_in_history_and_activity(): void
    {
        $this->receiveLot('100', '150');
        $op = $this->consume('6', 'PACKING', ['reference_text' => '  ORDER  42  '])->assertCreated()->assertJsonPath('data.reference_text', 'ORDER 42')->json('data');
        $this->consume('2', 'ADMIN')->assertCreated()->assertJsonPath('data.reason', 'ADMIN');

        $audit = DB::table('audit_logs')->where('action', 'inventory.internal_consumption')->where('entity_id', $op['id'])->sole();
        $after = json_decode((string) $audit->after, true);
        $this->assertSame($this->owner->id, $audit->actor_user_id);
        $this->assertSame('inventory_operation', $audit->entity_type);
        $this->assertSame(['number' => $op['number'], 'reason_code' => 'PACKING', 'reference_text' => 'ORDER 42'],
            array_intersect_key($after, array_flip(['number', 'reason_code', 'reference_text'])));
        $this->assertNotEmpty($after['movements']);

        $list = $this->get_("inventory/operations?type=INTERNAL_CONSUMPTION&product_id={$this->plastic}")->assertOk()->json();
        $this->assertSame(2, $list['meta']['total']);
        $row = collect($list['data'])->firstWhere('id', $op['id']);
        $this->assertSame(['PACKAGING', 'PLASTIK-15'], [$row['product']['classification'], $row['product']['sku']]);
        $this->assertSame($this->owner->id, $row['actor_user_id']);
        $this->assertSame('DG-WAY-PRIMARY', $row['node']['code']);
        $this->get_('inventory/operations?reason_code=ADMIN')->assertOk()->assertJsonPath('meta.total', 1);

        $events = collect($this->get_('activity?date=2026-10-03&type=INTERNAL_CONSUMPTION')->assertOk()->json('data'));
        $this->assertCount(2, $events);
        $this->assertSame(['inventory_operation', $op['number'], '6.0000', 'PACKING'],
            [$events->firstWhere('document.id', $op['id'])['document']['type'], $events->firstWhere('document.id', $op['id'])['document']['number'],
                $events->firstWhere('document.id', $op['id'])['quantity'], $events->firstWhere('document.id', $op['id'])['detail']]);

        $movements = $this->get_("inventory/movements?product_id={$this->plastic}&movement_type=INTERNAL_CONSUMPTION")->assertOk()->json('data');
        $this->assertSame([$op['number'], 'PACKING', 'PACKAGING'], [$movements[1]['source']['number'], $movements[1]['reason_code'], $movements[1]['classification']]);
        $this->assertNotNull($movements[1]['lot']['number']);
    }

    public function test_classification_is_context_not_a_permission_and_sellable_flows_are_unchanged(): void
    {
        // a SELLABLE product may be consumed (e.g. eggs used as samples)
        $this->receive([$this->line('75', '25000')]);
        $this->consume('1', 'SAMPLE', [], null, $this->egg)->assertCreated();
        $this->assertSame('74.0000', $this->stock()['available']);

        // ... and a PACKAGING product can still be sold: classification never blocks a sale
        $this->receiveLot('10', '150');
        $customer = $this->cmd('POST', 'business-partners', ['code' => 'CUST-A', 'name' => 'Customer A', 'party_type' => 'ORGANIZATION', 'customer' => ['customer_type' => 'RETAIL', 'term_days' => 0]])
            ->assertCreated()->json('data.id');
        $this->cmd('POST', 'sales', [
            'customer_id' => $customer, 'node_id' => $this->node, 'confirm' => true,
            'lines' => [['product_id' => $this->plastic, 'quantity' => '3', 'unit_price' => '500']],
            'payments' => [['method' => 'CASH', 'amount' => '1500']],
        ])->assertCreated();
        $this->assertSame('7.0000', $this->stock($this->plastic)['available']);

        // the dashboard does not count consumption as a loss
        $this->get_('dashboard?date=2026-10-03')->assertOk()->assertJsonPath('data.inventory.losses_on_date', '0.0000');
        $this->assertLedgerConsistent();
    }

    public function test_the_database_rejects_inconsistent_consumption_rows(): void
    {
        $this->receiveLot('10', '150');
        $op = $this->consume('1')->assertCreated()->json('data');
        $row = (array) DB::table('inventory_operations')->where('id', $op['id'])->first();
        $insert = fn (array $patch) => DB::table('inventory_operations')->insert(array_merge($row, ['id' => (string) Str::orderedUuid(), 'number' => 'INV-261003-9'.random_int(10, 99)], $patch));

        foreach ([
            ['reason_code' => null],                                         // consumption needs a code
            ['reason_code' => 'COUNT_CORRECTION'],                           // an adjustment code
            ['operation_type' => 'LOSS'],                                    // loss with a reason code
            ['reference_text' => '   '],                                     // blank reference
            ['counted_quantity' => '1', 'system_quantity' => '2'],           // count snapshot on a consumption
        ] as $patch) {
            try {
                DB::transaction(fn () => $insert($patch));
                $this->fail('accepted: '.json_encode($patch));
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('check', strtolower($e->getMessage()));
            }
        }
        // INTERNAL_CONSUMPTION movements are OUT only
        $m = (array) DB::table('stock_movements')->where('source_document_id', $op['id'])->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stock_movements')->insert(array_diff_key(array_merge($m, ['id' => (string) Str::orderedUuid(), 'direction' => 1]), ['sequence' => 0, 'signed_quantity' => 0]));
    }
}
