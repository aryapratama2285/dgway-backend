<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;

final class PurchaseOrderFlowTest extends InventoryTestCase
{
    private function createPo(string $qty = '500', string $cost = '23000'): array
    {
        return $this->cmd('POST', 'purchase-orders', [
            'supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => $qty, 'unit_cost' => $cost]],
        ])->assertCreated()->json('data');
    }

    private function confirmedPo(string $qty = '500'): array
    {
        $po = $this->createPo($qty);

        return $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'CONFIRMED')->json('data');
    }

    private function receiveAgainst(array $po, string $qty, ?string $cost = null): string
    {
        return $this->receive([$this->line($qty, $cost, [], null, $po['lines'][0]['id'])], $po['id']);
    }

    public function test_po_is_created_as_draft_with_numbered_document_and_exact_totals(): void
    {
        $po = $this->createPo('150', '23000');
        $this->assertMatchesRegularExpression('/^PO-261003-\d{3}$/', $po['number']);
        $this->assertSame('DRAFT', $po['status']);
        $this->assertSame('3450000.0000', $po['total_amount']);
        $this->assertSame('3450000.0000', $po['lines'][0]['line_total']);
        $this->assertSame('150.0000', $po['lines'][0]['open_quantity']);
        // a PO alone never touches stock
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->get_("purchase-orders/{$po['id']}")->assertOk()->assertJsonPath('data.number', $po['number']);
        $this->get_('purchase-orders?status=DRAFT')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_partial_receipts_500_equals_200_150_150(): void
    {
        $po = $this->confirmedPo('500');

        $this->receiveAgainst($po, '200');
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'PARTIALLY_RECEIVED')
            ->assertJsonPath('data.lines.0.received_quantity', '200.0000')->assertJsonPath('data.lines.0.open_quantity', '300.0000');

        $this->receiveAgainst($po, '150');
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'PARTIALLY_RECEIVED');

        $this->receiveAgainst($po, '150');
        $this->get_("purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.lines.0.open_quantity', '0.0000');

        $this->assertSame('500.0000', $this->stock()['available']);
        $this->assertSame(3, DB::table('goods_receipts')->where('purchase_order_id', $po['id'])->count());
        $this->assertLedgerConsistent();
    }

    public function test_unit_cost_defaults_from_po_line(): void
    {
        $po = $this->confirmedPo('100');
        $id = $this->receiveAgainst($po, '40');
        $this->get_("goods-receipts/{$id}")->assertJsonPath('data.lines.0.unit_cost', '23000.0000')
            ->assertJsonPath('data.type', 'PURCHASE_ORDER')->assertJsonPath('data.total_cost', '920000.0000');
        $this->assertSame('23000.0000', (string) DB::table('stock_movements')->where('source_document_id', $id)->value('unit_cost'));
    }

    public function test_over_receipt_is_rejected_by_default(): void
    {
        $po = $this->confirmedPo('100');
        $this->receiveAgainst($po, '90');

        $gr = $this->createReceipt([$this->line('20', null, [], null, $po['lines'][0]['id'])], $po['id']);
        $gr->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');

        // a draft that fits at creation time is re-checked at posting time
        $a = $this->createReceipt([$this->line('6', null, [], null, $po['lines'][0]['id'])], $po['id'])->assertCreated()->json('data.id');
        $b = $this->createReceipt([$this->line('6', null, [], null, $po['lines'][0]['id'])], $po['id'])->assertCreated()->json('data.id');
        $this->cmd('POST', "goods-receipts/{$a}/post")->assertOk();
        $this->cmd('POST', "goods-receipts/{$b}/post")->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        $this->get_("goods-receipts/{$b}")->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame('96.0000', $this->stock()['available']);
    }

    public function test_receiving_requires_a_confirmed_po(): void
    {
        $po = $this->createPo('100');
        $this->createReceipt([$this->line('10', null, [], null, $po['lines'][0]['id'])], $po['id'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_RECEIVABLE');
    }

    public function test_cancel_rules_and_invalid_transitions(): void
    {
        $po = $this->createPo('100');
        $this->cmd('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => ''])->assertStatus(422);
        $this->cmd('POST', "purchase-orders/{$po['id']}/cancel", ['reason' => 'supplier batal'])->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancel_reason', 'supplier batal');
        // CANCELLED is terminal
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->createReceipt([$this->line('10', null, [], null, $po['lines'][0]['id'])], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'PO_NOT_RECEIVABLE');

        $po2 = $this->confirmedPo('100');
        $this->receiveAgainst($po2, '10');
        $this->cmd('POST', "purchase-orders/{$po2['id']}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'PO_HAS_RECEIPTS');
    }

    public function test_stale_expected_version_returns_409(): void
    {
        $po = $this->createPo('100');
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm", ['expected_version' => $po['version'] + 5])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm", ['expected_version' => $po['version']])->assertOk();
    }

    public function test_audit_events_for_purchase_orders(): void
    {
        $po = $this->confirmedPo('100');
        $po2 = $this->createPo('50');
        $this->cmd('POST', "purchase-orders/{$po2['id']}/cancel", ['reason' => 'tidak jadi'])->assertOk();

        $actions = DB::table('audit_logs')->whereIn('entity_id', [$po['id'], $po2['id']])->pluck('action')->all();
        sort($actions);
        $this->assertSame(['purchase_order.cancelled', 'purchase_order.confirmed', 'purchase_order.created', 'purchase_order.created'], $actions);
    }
}
