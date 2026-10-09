<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F4A — purchase order lifecycle: draft edit, confirm (terms frozen), cancel
 * before receipt only, CLOSE with a reason that keeps ordered/received/unfulfilled.
 */
final class PurchaseOrderLifecycleTest extends ProcurementTestCase
{
    public function test_create_draft_with_names_and_validation_errors(): void
    {
        $po = $this->createPo()->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.supplier.code', 'SUP-BANDUNG')
            ->assertJsonPath('data.node.code', 'DG-WAY-PRIMARY')
            ->assertJsonPath('data.lines.0.product.sku', 'TELUR-STD')
            ->assertJsonPath('data.lines.0.purchase_uom', 'KG')
            ->assertJsonPath('data.lines.0.conversion_factor', '1.000000')
            ->assertJsonPath('data.total_amount', '2350000.0000')
            ->assertJsonPath('data.confirmed_at', null)
            ->json('data');
        $this->assertMatchesRegularExpression('/^PO-261003-\d{3}$/', $po['number']);

        $customer = $this->cmd('POST', 'business-partners', ['code' => 'CUST-1', 'name' => 'C', 'party_type' => 'ORGANIZATION', 'customer' => ['customer_type' => 'RETAIL']])->json('data.id');
        $this->createPo(null, $customer)->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_USABLE');
        $this->createPo([])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->createPo([['product_id' => $this->egg, 'quantity' => '0', 'unit_cost' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->createPo([['product_id' => $this->egg, 'quantity' => '1', 'unit_cost' => '-1']])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->createPo([['product_id' => $this->egg, 'quantity' => '1', 'unit_cost' => '1', 'uom' => 'IKAT']])->assertStatus(422)->assertJsonPath('error.code', 'UOM_CONVERSION_MISSING');
        $this->createPo([['product_id' => $this->egg, 'quantity' => '1.12345', 'unit_cost' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_PRECISION_EXCEEDED');
    }

    public function test_draft_is_editable_then_terms_freeze_on_confirm(): void
    {
        $po = $this->createPo()->assertCreated()->json('data');
        $other = $this->product('TELUR-AFKIR');

        $edited = $this->cmd('PATCH', "purchase-orders/{$po['id']}", [
            'expected_version' => $po['version'], 'notes' => 'revisi harga',
            'lines' => [
                ['product_id' => $this->egg, 'quantity' => '120', 'unit_cost' => '23400'],
                ['product_id' => $other, 'quantity' => '10', 'unit_cost' => '15000'],
            ],
        ])->assertOk()->assertJsonPath('data.number', $po['number'])->assertJsonPath('data.notes', 'revisi harga')
            ->assertJsonPath('data.total_amount', '2958000.0000')->assertJsonPath('data.lines.1.product.sku', 'TELUR-AFKIR')->json('data');
        $this->assertSame(2, DB::table('purchase_order_lines')->where('purchase_order_id', $po['id'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purchase_order.updated')->count());

        // stale version → 409; version is required on PATCH
        $this->cmd('PATCH', "purchase-orders/{$po['id']}", ['expected_version' => $po['version'], 'notes' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->cmd('PATCH', "purchase-orders/{$po['id']}", ['notes' => 'x'])->assertStatus(422);

        $confirmed = $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk()->json('data');
        $this->assertNotNull($confirmed['confirmed_at']);
        $this->cmd('PATCH', "purchase-orders/{$po['id']}", ['expected_version' => $confirmed['version'], 'notes' => 'telat'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION')->assertJsonPath('error.details.action', 'edit');

        // the database refuses it too
        $line = $edited['lines'][0]['id'];
        $this->expectDbRejects(fn () => DB::table('purchase_order_lines')->where('id', $line)->update(['ordered_quantity' => 1, 'purchase_quantity' => 1, 'line_total' => 23400]));
        $this->expectDbRejects(fn () => DB::table('purchase_orders')->where('id', $po['id'])->update(['supplier_id' => $this->supplier('SUP-X')]));
        $this->expectDbRejects(fn () => DB::table('purchase_order_lines')->insert([
            'id' => (string) Str::uuid7(), 'purchase_order_id' => $po['id'], 'line_no' => 9, 'product_id' => $this->product('TELUR-Y'), 'uom_code' => 'KG',
            'ordered_quantity' => 1, 'received_quantity' => 0, 'unit_cost' => 1, 'line_total' => 1,
            'purchase_uom_code' => 'KG', 'purchase_quantity' => 1, 'purchase_unit_price' => 1, 'conversion_factor' => 1,
        ]));
    }

    public function test_cancel_before_receipt_but_not_after(): void
    {
        $po = $this->confirmedPo();
        $cancelled = $this->cmd('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'supplier tutup'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')->json('data');
        $this->assertNotNull($cancelled['cancelled_at']);
        $this->expectDbRejects(fn () => DB::table('purchase_orders')->where('id', $po['id'])->update(['notes' => 'x']), 'terminal');

        $po2 = $this->confirmedPo();
        $this->receivePo($po2, [$this->poLine($po2, '10')]);
        $this->cmd('POST', "purchase-orders/{$po2['id']}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'PO_HAS_RECEIPTS');
    }

    public function test_close_partial_keeps_ordered_received_and_unfulfilled(): void
    {
        $po = $this->confirmedPo();
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION'); // CONFIRMED: cancel instead

        $this->receivePo($po, [$this->poLine($po, '90')]);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'OTHER'])->assertStatus(422)->assertJsonPath('error.code', 'CLOSE_REASON_REQUIRED');
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'BOGUS'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $closed = $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL', 'note' => 'stok peternak habis'])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.close_reason_code', 'SUPPLIER_SHORTFALL')
            ->assertJsonPath('data.lines.0.ordered_quantity', '100.0000')
            ->assertJsonPath('data.lines.0.received_quantity', '90.0000')
            ->assertJsonPath('data.lines.0.unfulfilled_quantity', '10.0000')
            ->json('data');
        $this->assertNotNull($closed['closed_at']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purchase_order.closed')->count());

        // terminal: no receipt, no cancel, no reversal, no edit, no second close
        $this->createReceipt([$this->poLine($po, '5')], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_RECEIVABLE');
        $this->cmd('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'x'])->assertStatus(422);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'PURCHASE_STOPPED'])->assertStatus(409);
        $grId = DB::table('goods_receipts')->where('purchase_order_id', $po['id'])->value('id');
        $this->cmd('POST', "goods-receipts/{$grId}/reverse", ['reason' => 'salah'])->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame('90.0000', $this->stock()['available']);
        $this->get_('purchase-orders?status=CLOSED')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertLedgerConsistent();
    }

    public function test_close_received_order_and_open_filter(): void
    {
        $po = $this->confirmedPo();
        $this->get_('purchase-orders?status=OPEN')->assertOk()->assertJsonPath('meta.total', 1);
        $this->receivePo($po, [$this->poLine($po, '100')]);
        $this->assertSame('RECEIVED', $this->po($po['id'])['status']);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'PURCHASE_STOPPED'])->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.lines.0.unfulfilled_quantity', '0.0000')
            ->assertJsonPath('data.receiving.posted_receipts', 1);
        $this->get_('purchase-orders?status=OPEN')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_activity_and_dashboard_show_the_lifecycle(): void
    {
        $po = $this->confirmedPo();
        $this->receivePo($po, [$this->poLine($po, '60')]);
        $this->get_('dashboard')->assertOk()->assertJsonPath('data.purchasing.open_orders_now.count', 1)
            ->assertJsonPath('data.purchasing.open_orders_now.partially_received', 1);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL'])->assertOk();
        $po2 = $this->createPo()->json('data');
        $this->cmd('POST', "purchase-orders/{$po2['id']}/cancel", ['reason' => 'batal'])->assertOk();

        $this->get_('activity?date=2026-10-03&type=PURCHASE_ORDER_CONFIRMED')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get_('activity?date=2026-10-03&type=PURCHASE_ORDER_CLOSED')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.detail', 'SUPPLIER_SHORTFALL')->assertJsonPath('data.0.document.number', $po['number']);
        $this->get_('activity?date=2026-10-03&type=PURCHASE_ORDER_CANCELLED')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.detail', 'batal');
        $this->get_('dashboard')->assertOk()->assertJsonPath('data.purchasing.open_orders_now.count', 0);
    }

    public function test_permissions_gate_close_cancel_and_reverse(): void
    {
        $po = $this->confirmedPo();
        $gr = $this->receivePo($po, [$this->poLine($po, '10')]);
        config(['dgway.authorization.role_permissions.OWNER' => []]);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->cmd('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'x'])->assertStatus(403);
        $this->cmd('POST', "goods-receipts/{$gr['id']}/reverse", ['reason' => 'x'])->assertStatus(403);
        $this->cmd('PUT', "products/{$this->egg}/uom-conversions/IKAT", ['factor_to_base' => '2'])->assertStatus(403);
        // routine actions stay open
        $this->receivePo($po, [$this->poLine($po, '5')]);
        $this->assertSame('PARTIALLY_RECEIVED', $this->po($po['id'])['status']);
    }

    public function test_f4a_migration_rollback_refuses_to_destroy_history(): void
    {
        $migration = require base_path('database/migrations/2026_10_09_100100_f4a_procurement_lifecycle_and_purchase_units.php');
        $po = $this->confirmedPo();
        $this->receivePo($po, [$this->poLine($po, '10')]);
        $this->cmd('POST', "purchase-orders/{$po['id']}/close", ['reason_code' => 'SUPPLIER_SHORTFALL'])->assertOk();
        try {
            $migration->down();
            $this->fail('rollback ran although F4A data exists');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 CLOSED purchase order', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('product_uom_conversions'), 'nothing was dropped');
        $this->assertSame('CLOSED', DB::table('purchase_orders')->where('id', $po['id'])->value('status'));
    }

    private function expectDbRejects(\Closure $sql, string $messagePart = ''): void
    {
        try {
            DB::transaction($sql);
            $this->fail('The database accepted a forbidden statement.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0] ?? '', ['23001', '23514'], $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }
}
