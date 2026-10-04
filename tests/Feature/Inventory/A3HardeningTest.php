<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;

/** Gaps reported at the end of A3, closed in A4 (A4 §38). */
final class A3HardeningTest extends InventoryTestCase
{
    private function draft(string $qty = '10'): array
    {
        return $this->createReceipt([$this->line($qty)])->assertCreated()->json('data');
    }

    // ── A. expected_version on post / reverse / cancel ──────────────────

    public function test_stale_expected_version_on_post_is_rejected(): void
    {
        $gr = $this->draft();
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", ['expected_version' => $gr['version'] + 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post", ['expected_version' => $gr['version']])->assertOk();
    }

    public function test_stale_expected_version_on_reverse_is_rejected(): void
    {
        $gr = $this->draft();
        $posted = $this->cmd('POST', "goods-receipts/{$gr['id']}/post")->assertOk()->json('data');
        $this->cmd('POST', "goods-receipts/{$gr['id']}/reverse", ['reason' => 'x', 'expected_version' => $gr['version']])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'REVERSAL')->count());
        $this->cmd('POST', "goods-receipts/{$gr['id']}/reverse", ['reason' => 'x', 'expected_version' => $posted['version']])->assertOk();
    }

    public function test_stale_expected_version_on_cancel_is_rejected(): void
    {
        $gr = $this->draft();
        $this->cmd('POST', "goods-receipts/{$gr['id']}/cancel", ['reason' => 'x', 'expected_version' => $gr['version'] + 3])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->get_("goods-receipts/{$gr['id']}")->assertJsonPath('data.status', 'DRAFT');
        $this->cmd('POST', "goods-receipts/{$gr['id']}/cancel", ['reason' => 'x', 'expected_version' => $gr['version']])->assertOk();
    }

    // ── B. inactive master data on NEW transactions ─────────────────────

    public function test_inactive_supplier_role_blocks_new_receipts_and_posting(): void
    {
        $draft = $this->draft();
        $this->cmd('POST', "business-partners/{$this->supplier}/roles/supplier/deactivate")->assertOk();
        $this->createReceipt([$this->line('1')])->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_USABLE');
        $this->cmd('POST', "goods-receipts/{$draft['id']}/post")->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_USABLE');
        $this->cmd('POST', 'purchase-orders', ['supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => '1', 'unit_cost' => '1']]])->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_USABLE');
        $this->get_("goods-receipts/{$draft['id']}")->assertOk(); // history readable
    }

    public function test_inactive_node_blocks_new_transactions(): void
    {
        $this->receive([$this->line('10')]);
        $draft = $this->draft();
        $this->cmd('POST', "nodes/{$this->node}/deactivate")->assertOk();
        $this->createReceipt([$this->line('1')])->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');
        $this->cmd('POST', "goods-receipts/{$draft['id']}/post")->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');
        $this->assertSame('10.0000', $this->stock()['available']); // still readable
    }

    public function test_inactive_product_blocks_new_transactions(): void
    {
        $this->receive([$this->line('10')]);
        $this->cmd('POST', "products/{$this->egg}/deactivate")->assertOk();
        $this->createReceipt([$this->line('1')])->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
        $this->cmd('POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'direction' => 'IN', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
    }

    // ── C. recovery from QUARANTINE ─────────────────────────────────────

    public function test_recovery_from_quarantine_into_available(): void
    {
        // a customer return to quarantine is the A4 way stock lands there; here: receipt + adjustment path
        $afkir = $this->product('TELUR-AFKIR');
        $this->receive([$this->line('20')]);
        // nothing in QUARANTINE yet → recovery refused
        $this->cmd('POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'from_bucket' => 'QUARANTINE', 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');
        // put 4 into QUARANTINE through the ledger (customer return to quarantine, A4)
        $customer = $this->cmd('POST', 'business-partners', ['code' => 'CUS-Q', 'name' => 'Q', 'party_type' => 'INDIVIDUAL', 'customer' => ['customer_type' => 'RETAIL']])->json('data.id');
        $sale = $this->cmd('POST', 'sales', ['customer_id' => $customer, 'node_id' => $this->node, 'confirm' => true,
            'lines' => [['product_id' => $this->egg, 'quantity' => '5', 'unit_price' => '26000']], 'payments' => [['method' => 'CASH', 'amount' => '130000']]])->assertCreated()->json('data');
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'retak, cek dulu', 'refund_method' => 'CASH',
            'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '4', 'disposition' => 'RETURN_TO_QUARANTINE']]])->assertCreated();
        $this->assertSame('4.0000', $this->stock()['quarantine']);

        $this->cmd('POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '3', 'from_bucket' => 'QUARANTINE', 'reason' => 'lolos cek'])
            ->assertCreated()->assertJsonPath('data.from_bucket', 'QUARANTINE');
        $s = $this->stock();
        $this->assertSame('1.0000', $s['quarantine']);
        $this->assertSame('18.0000', $s['available']); // 20 − 5 + 3
        $this->assertLedgerConsistent();
        unset($afkir);
    }

    // ── E. list endpoints are paged ─────────────────────────────────────

    public function test_balances_are_paged(): void
    {
        foreach (['TLR-B', 'TLR-C', 'TLR-D'] as $sku) {
            $this->receive([$this->line('1', '1', [], $this->product($sku))]);
        }
        $this->receive([$this->line('1')]);
        $res = $this->get_('inventory/balances?per_page=2&page=2')->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(4, $res->json('meta.total'));
        $this->assertSame(2, $res->json('meta.last_page'));
        $this->get_('inventory/balances?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100); // capped
    }
}
