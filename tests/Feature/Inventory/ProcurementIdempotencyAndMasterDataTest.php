<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProcurementIdempotencyAndMasterDataTest extends InventoryTestCase
{
    public function test_retry_with_same_key_never_doubles_stock(): void
    {
        $lines = [$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])];
        $createKey = (string) Str::uuid();
        $first = $this->createReceipt($lines, null, $createKey)->assertCreated();
        $replay = $this->createReceipt($lines, null, $createKey)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, DB::table('goods_receipts')->count());

        $id = $first->json('data.id');
        $postKey = (string) Str::uuid();
        $p1 = $this->cmd('POST', "goods-receipts/{$id}/post", [], $postKey)->assertOk();
        $p2 = $this->cmd('POST', "goods-receipts/{$id}/post", [], $postKey)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($p1->json(), $p2->json());

        $this->assertSame('140.0000', $this->stock()['available']); // not 280
        $this->assertSame(3, DB::table('stock_movements')->count());
        // a second post with a NEW key is a business error, not a duplicate posting
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(3, DB::table('stock_movements')->count());

        // same key, different body → rejected
        $this->createReceipt([$this->line('151')], null, $createKey)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_idempotent_inventory_operation(): void
    {
        $this->receive([$this->line('10')]);
        $key = (string) Str::uuid();
        $body = ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '3', 'reason' => 'pecah'];
        $this->cmd('POST', 'inventory/losses', $body, $key)->assertCreated();
        $this->cmd('POST', 'inventory/losses', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame('7.0000', $this->stock()['available']);
        $this->assertSame(1, DB::table('inventory_operations')->count());
    }

    public function test_mutations_require_an_idempotency_key(): void
    {
        $this->postJson('/api/v1/goods-receipts', [])->assertStatus(428);
        $this->postJson('/api/v1/inventory/losses', [])->assertStatus(428);
    }

    public function test_dates_are_kept_distinct(): void
    {
        $id = $this->createReceipt([$this->line('10')], null)->json('data.id');
        $this->cmd('POST', 'goods-receipts', [
            'supplier_id' => $this->supplier, 'node_id' => $this->node, 'business_date' => '2026-10-01', 'received_at' => '2026-10-01T22:30:00+07:00',
            'lines' => [$this->line('5')],
        ])->assertCreated()->assertJsonPath('data.business_date', '2026-10-01')->assertJsonPath('data.number', 'GR-261001-001');
        $this->clock->travelTo('2026-10-03T05:00:00Z');
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk();

        $m = DB::table('stock_movements')->first();
        $this->assertSame('2026-10-03', (string) $m->business_date);
        $this->assertNotNull($m->occurred_at);
        $this->assertNotNull($m->created_at);
        $this->assertStringStartsWith('2026-10-03 05:00:00', (string) DB::selectOne("SELECT occurred_at AT TIME ZONE 'UTC' AS t FROM stock_movements LIMIT 1")->t);
    }

    public function test_new_transactions_validate_master_data(): void
    {
        // customer-only partner is not a supplier
        $customer = $this->cmd('POST', 'business-partners', ['code' => 'CUS-A', 'name' => 'Cust', 'party_type' => 'INDIVIDUAL', 'customer' => ['customer_type' => 'UMKM']])->json('data.id');
        $this->cmd('POST', 'goods-receipts', ['supplier_id' => $customer, 'node_id' => $this->node, 'lines' => [$this->line('1')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_USABLE');

        // node without RECEIVING
        $pickup = $this->node('PICKUP-1', ['PICKUP']);
        $this->cmd('POST', 'goods-receipts', ['supplier_id' => $this->supplier, 'node_id' => $pickup, 'lines' => [$this->line('1')]])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');

        // UOM other than base is rejected, never converted
        $l = $this->line('1');
        $l['uom'] = 'PCS';
        $this->createReceipt([$l])->assertStatus(422)->assertJsonPath('error.code', 'UOM_NOT_BASE');
        $l['uom'] = 'kg';
        $this->createReceipt([$l])->assertCreated();

        // unknown product
        $this->createReceipt([$this->line('1', '1', [], (string) Str::uuid7())])->assertStatus(404);

        // inactive product, inactive supplier role
        $draft = $this->createReceipt([$this->line('4')])->json('data.id');
        $this->receive([$this->line('10')]);
        $this->cmd('POST', "products/{$this->egg}/deactivate")->assertOk();
        $this->createReceipt([$this->line('1')])->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
        // posting re-validates: a draft made before deactivation cannot be posted
        $this->cmd('POST', "goods-receipts/{$draft}/post")->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');

        $this->cmd('POST', "business-partners/{$this->supplier}/roles/supplier/deactivate")->assertOk();
        // history stays readable after deactivation
        $this->get_('goods-receipts?status=POSTED')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get_("goods-receipts/{$draft}")->assertOk();
        $this->assertSame('10.0000', $this->stock()['available']);
        $this->get_('inventory/movements')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
