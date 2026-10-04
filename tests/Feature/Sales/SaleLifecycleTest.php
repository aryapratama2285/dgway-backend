<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;

final class SaleLifecycleTest extends SalesTestCase
{
    public function test_direct_sale_draft_then_confirm_without_any_order(): void
    {
        $this->stockUp('140');
        $draft = $this->sell(null, '30', '26000', false)->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.origin', 'DIRECT')->assertJsonPath('data.order_reference_id', null)
            ->assertJsonPath('data.payment_status', 'NONE')->assertJsonPath('data.outstanding', '0.0000')->json('data');
        $this->assertSame('140.0000', $this->stock()['available'], 'a draft does not touch stock');

        $this->cmd('POST', "sales/{$draft['id']}/confirm", ['payments' => [$this->cash('780000')]])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.confirmed_at', '2026-10-03T01:00:00+00:00');
        $this->assertSame('110.0000', $this->stock()['available']);
        $this->cmd('POST', "sales/{$draft['id']}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
    }

    public function test_insufficient_stock_rejects_the_whole_sale_without_partial_effects(): void
    {
        $this->stockUp('20');
        $afkir = $this->product('TELUR-AFKIR');
        $this->stockUp('5', $afkir);

        $res = $this->cmd('POST', 'sales', ['node_id' => $this->node, 'confirm' => true,
            'lines' => [['product_id' => $this->egg, 'quantity' => '10', 'unit_price' => '26000'], ['product_id' => $afkir, 'quantity' => '6', 'unit_price' => '15000']],
            'payments' => [$this->cash('350000')]]);
        $res->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')->assertJsonPath('error.details.product_id', $afkir);

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame('20.0000', $this->stock()['available']);
        $this->assertSame('5.0000', $this->stock($afkir)['available']);
        // no document number burned
        $this->sell(null, '1', '26000', true, [$this->cash('26000')])->assertCreated()->assertJsonPath('data.number', 'SAL-261003-001');
        $this->assertSame('PAY-261003-001', DB::table('payments')->value('number'));
        $this->assertLedgerConsistent();
    }

    public function test_cash_customer_and_walk_in_cannot_owe(): void
    {
        $this->stockUp();
        $cashCustomer = $this->customer('CUS-WARUNG', 0);
        $this->sell($cashCustomer, '10', '26000', true, [$this->cash('100000')])->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_NOT_ALLOWED');
        $this->sell(null, '2', '27000', true)->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_NOT_ALLOWED');
        $walkInPartner = $this->customer('CUS-UMUM', 0, null, 'WALK_IN');
        $this->sell($walkInPartner, '2', '27000', true, [$this->cash('20000')])->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_NOT_ALLOWED');
        $this->sell($walkInPartner, '2', '27000', true, [$this->cash('54000')])->assertCreated()->assertJsonPath('data.status', 'COMPLETED');
        $this->sell(null, '2', '27000', true, [$this->cash('60000')])->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_OUTSTANDING');
    }

    public function test_credit_limit_is_enforced(): void
    {
        $this->stockUp('200');
        $abc = $this->customer('CUS-ABC', 14, '1000000');
        $this->sell($abc, '28', '25000', true)->assertCreated()->assertJsonPath('data.outstanding', '700000.0000'); // exposure 700k
        $this->sell($abc, '13', '25000', true)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED')
            ->assertJsonPath('error.details.available_credit', '300000.0000')->assertJsonPath('error.details.requested_credit', '325000.0000');
        $this->sell($abc, '13', '25000', true, [$this->cash('25000')])->assertCreated(); // 300k on credit: exactly at limit
        $this->sell($abc, '1', '1', true)->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED');
        $this->sell($abc, '1', '25000', true, [$this->cash('25000')])->assertCreated(); // paying in full needs no credit
        $this->get_('receivables/customers')->assertOk()->assertJsonPath('data.0.outstanding', '1000000.0000')->assertJsonPath('data.0.available_credit', '0.0000');
    }

    public function test_due_dates_and_overdue_receivables(): void
    {
        $this->stockUp('200');
        $abc = $this->customer('CUS-ABC', 7);
        $xyz = $this->customer('CUS-XYZ', 30);
        $s1 = $this->sell($abc, '40', '25000', true)->json('data');  // 1 000 000 due 10 Oct
        $this->pay($s1['id'], '700000')->assertCreated();
        $this->sell($xyz, '10', '25000', true)->assertJsonPath('data.due_date', '2026-11-02');

        $this->get_('receivables/summary')->assertOk()->assertJsonPath('data.total', '550000.0000')
            ->assertJsonPath('data.overdue', '0.0000')->assertJsonPath('data.not_yet_due', '550000.0000');

        $this->clock->travelTo('2026-10-12T02:00:00Z');
        $this->get_('receivables/summary')->assertJsonPath('data.overdue', '300000.0000')->assertJsonPath('data.overdue_customers', 1);
        $this->get_('receivables?overdue=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.days_overdue', 2)
            ->assertJsonPath('data.0.status', 'OVERDUE')->assertJsonPath('data.0.customer.code', 'CUS-ABC');
        $this->get_("sales/{$s1['id']}")->assertJsonPath('data.is_overdue', true);
        $this->clock->travelTo('2026-11-02T02:00:00Z');
        $this->get_('receivables/summary')->assertJsonPath('data.due_today', '250000.0000');
    }

    public function test_payment_reversal_keeps_history_and_reopens_receivable(): void
    {
        $this->stockUp();
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '40', '25000', true, [$this->cash('1000000')])->assertJsonPath('data.status', 'COMPLETED')->json('data');
        $paymentId = DB::table('payments')->value('id');

        $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'transfer belum masuk'])->assertOk()
            ->assertJsonPath('data.status', 'REVERSED')->assertJsonPath('data.reversal.reason', 'transfer belum masuk')
            ->assertJsonPath('data.sale.status', 'CONFIRMED')->assertJsonPath('data.sale.outstanding', '1000000.0000');
        $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'lagi'])->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_ALREADY_REVERSED');

        $this->assertSame(1, DB::table('payments')->count(), 'the payment row is kept');
        $this->assertSame('1000000.0000', (string) DB::table('payments')->value('amount'));
        $this->get_("payments?status=REVERSED")->assertJsonPath('meta.total', 1);
        $this->pay($sale['id'], '1000000', 'BANK_TRANSFER')->assertCreated()->assertJsonPath('data.sale.status', 'COMPLETED');
    }

    public function test_sale_reversal_returns_stock_traceably(): void
    {
        $this->stockUp('140');
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '30', '26000', true, [$this->cash('500000')])->json('data');
        $this->assertSame('110.0000', $this->stock()['available']);

        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'salah customer'])->assertStatus(409)->assertJsonPath('error.code', 'SALE_HAS_PAYMENTS');
        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'salah customer', 'reverse_payments' => true])->assertOk()
            ->assertJsonPath('data.status', 'REVERSED')->assertJsonPath('data.paid', '0.0000')->assertJsonPath('data.outstanding', '0.0000');
        $this->assertSame('140.0000', $this->stock()['available']);

        $saleMove = DB::table('stock_movements')->where('movement_type', 'SALE')->first();
        $rev = DB::table('stock_movements')->where('movement_type', 'REVERSAL')->first();
        $this->assertSame($saleMove->id, $rev->reverses_movement_id);
        $this->assertSame('sale', $rev->source_document_type);
        $this->assertSame(1, (int) $rev->direction);
        $this->assertSame(1, DB::table('payment_reversals')->count());
        $this->assertSame(1, DB::table('sales')->count(), 'original sale kept');

        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'lagi', 'reverse_payments' => true])->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATUS_TRANSITION');
        $this->get_('receivables/summary')->assertJsonPath('data.total', '0.0000');
        $this->assertLedgerConsistent();
    }

    public function test_sale_reversal_blocked_when_stock_already_gone_or_returned(): void
    {
        $this->stockUp('30');
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '10', '26000', true)->json('data');
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'pecah', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '1', 'disposition' => 'LOSS']]])->assertCreated();
        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'SALE_HAS_RETURNS');
    }

    public function test_draft_cancel(): void
    {
        $this->stockUp();
        $draft = $this->sell(null, '1', '26000', false)->json('data');
        $this->cmd('POST', "sales/{$draft['id']}/cancel", ['reason' => 'batal'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->cmd('POST', "sales/{$draft['id']}/confirm", ['payments' => [$this->cash('26000')]])->assertStatus(409);
        $this->cmd('POST', "sales/{$draft['id']}/reverse", ['reason' => 'x'])->assertStatus(409);
    }

    public function test_line_discount(): void
    {
        $this->stockUp();
        $this->sell(null, '35', '26000', true, [$this->cash('900000')], null, ['discount' => '10000'])->assertCreated()
            ->assertJsonPath('data.subtotal', '910000.0000')->assertJsonPath('data.discount_total', '10000.0000')->assertJsonPath('data.grand_total', '900000.0000');
        $this->sell(null, '1', '26000', false, [], null, ['discount' => '26001'])->assertStatus(422)->assertJsonPath('error.code', 'DISCOUNT_EXCEEDS_SUBTOTAL');
    }

    public function test_price_is_a_snapshot_on_the_line(): void
    {
        $this->stockUp();
        $s1 = $this->sell(null, '1', '26000', true, [$this->cash('26000')])->json('data');
        $this->sell(null, '1', '26500', true, [$this->cash('26500')]);
        $this->get_("sales/{$s1['id']}")->assertJsonPath('data.lines.0.unit_price', '26000.0000');
    }

    public function test_stale_expected_version(): void
    {
        $this->stockUp();
        $abc = $this->customer('CUS-ABC', 7);
        $draft = $this->sell($abc, '10', '25000', false)->json('data');
        $this->cmd('POST', "sales/{$draft['id']}/confirm", ['expected_version' => $draft['version'] + 1])->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $confirmed = $this->cmd('POST', "sales/{$draft['id']}/confirm", ['expected_version' => $draft['version']])->assertOk()->json('data');
        $this->cmd('POST', "sales/{$draft['id']}/payments", ['method' => 'CASH', 'amount' => '1', 'expected_version' => $draft['version']])->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->cmd('POST', "sales/{$draft['id']}/payments", ['method' => 'CASH', 'amount' => '1', 'expected_version' => $confirmed['version']])->assertCreated();
    }
}
