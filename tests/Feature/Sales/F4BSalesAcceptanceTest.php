<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkerProcess;

/**
 * Phase F4B acceptance: the outbound loop Customer → Sale → Stock out →
 * Receivable → Payment, plus the F4B delta (OTHER, lots on the sale detail,
 * correction permissions) and the payment-versus-reversal race.
 */
final class F4BSalesAcceptanceTest extends SalesTestCase
{
    private function saleMovements(string $saleId): int
    {
        return DB::table('stock_movements')->where('source_document_type', 'sale')->where('source_document_id', $saleId)->where('movement_type', 'SALE')->count();
    }

    public function test_scenario_a_walk_in_cash_5_kg(): void
    {
        $this->stockUp('100');
        $sale = $this->sell(null, '5', '26000', true, [$this->cash('130000')])->assertCreated()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.payment_status', 'PAID')
            ->assertJsonPath('data.is_walk_in', true)
            ->assertJsonPath('data.grand_total', '130000.0000')
            ->assertJsonPath('data.outstanding', '0.0000')
            ->json('data');

        $this->assertSame('95.0000', $this->stock()['available'], 'exactly 5 KG left the stock');
        $this->assertSame(1, $this->saleMovements($sale['id']), 'one stock issue (one lot)');
        $payments = DB::table('payments')->where('sale_id', $sale['id'])->get();
        $this->assertCount(1, $payments);
        $this->assertSame('130000.0000', (string) $payments[0]->amount);
        $this->assertSame('CASH', $payments[0]->method);
        $this->assertLedgerConsistent();
    }

    public function test_scenario_b_b2b_partial_then_later_payment(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-RESTO-B', 7, '1000000');
        $sale = $this->sell($abc, '20', '25000', true, [$this->cash('200000', 'BANK_TRANSFER')])->assertCreated()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.payment_status', 'PARTIALLY_PAID')
            ->assertJsonPath('data.grand_total', '500000.0000')
            ->assertJsonPath('data.outstanding', '300000.0000')
            ->json('data');
        $this->assertSame('80.0000', $this->stock()['available']);
        $this->get_('receivables/customers')->assertJsonPath('data.0.outstanding', '300000.0000')->assertJsonPath('data.0.available_credit', '700000.0000');

        $this->pay($sale['id'], '300000')->assertCreated()
            ->assertJsonPath('data.sale.status', 'COMPLETED')
            ->assertJsonPath('data.sale.payment_status', 'PAID')
            ->assertJsonPath('data.sale.outstanding', '0.0000');
        $this->get_('receivables/summary')->assertJsonPath('data.total', '0.0000');
        $detail = $this->get_("sales/{$sale['id']}")->json('data');
        $this->assertSame(['BANK_TRANSFER', 'CASH'], array_column($detail['payments'], 'method'));
        $this->assertSame('80.0000', $this->stock()['available'], 'payments never touch stock');
    }

    public function test_fifo_lots_are_exposed_on_the_sale_detail(): void
    {
        $this->stockUp('60');
        $this->stockUp('30');
        $sale = $this->sell(null, '70', '26000', true, [$this->cash('1820000')])->assertCreated()->json('data');

        $lines = $this->get_("sales/{$sale['id']}")->assertOk()->json('data.lines');
        $product = DB::table('products')->where('id', $this->egg)->first(['id', 'sku', 'name']);
        $this->assertSame(['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name], $lines[0]['product']);
        $lots = $lines[0]['lots'];
        $this->assertCount(2, $lots);
        $this->assertSame(['60.0000', '10.0000'], array_column($lots, 'consumed'), 'oldest lot first');
        $this->assertSame(['0.0000', '0.0000'], array_column($lots, 'returned'));
        $this->assertNotSame($lots[0]['lot_id'], $lots[1]['lot_id']);
        $this->assertMatchesRegularExpression('/^LOT-/', $lots[0]['lot_number']);
        $this->assertSame(2, $this->saleMovements($sale['id']), 'one SALE movement per lot');

        // a return goes back into the consumed lots and shows up as returned
        $this->cmd('POST', "sales/{$sale['id']}/returns", [
            'reason' => 'pecah', 'refund_method' => 'CASH',
            'lines' => [['sale_line_id' => $lines[0]['id'], 'quantity' => '5', 'disposition' => 'LOSS']],
        ])->assertCreated();
        $after = $this->get_("sales/{$sale['id']}")->json('data.lines.0.lots');
        $this->assertSame(['5.0000', '0.0000'], array_column($after, 'returned'), 'back into the lots in allocation order');
        $this->assertSame(['60.0000', '10.0000'], array_column($after, 'consumed'), 'what was issued is history');

        // the list and command responses keep their shape (detail-only addition)
        $this->assertArrayNotHasKey('lots', $sale['lines'][0]);
        $this->assertLedgerConsistent();
    }

    public function test_other_payment_method_must_be_described(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-OTHER', 7);
        $sale = $this->sell($abc, '10', '25000', true)->assertCreated()->json('data');

        $this->cmd('POST', "sales/{$sale['id']}/payments", ['method' => 'OTHER', 'amount' => '50000'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED')->assertJsonPath('error.details.field', 'reference');
        $this->cmd('POST', "sales/{$sale['id']}/payments", ['method' => 'OTHER', 'amount' => '50000', 'reference' => '   '])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED');
        $this->cmd('POST', "sales/{$sale['id']}/payments", ['method' => 'OTHER', 'amount' => '50000', 'reference' => 'Potong deposit customer'])
            ->assertCreated()->assertJsonPath('data.method', 'OTHER')->assertJsonPath('data.reference', 'Potong deposit customer');
        $this->cmd('POST', "sales/{$sale['id']}/payments", ['method' => 'OTHER', 'amount' => '50000', 'notes' => 'Settlement manual'])
            ->assertCreated();
        // at sale creation (confirm + payments) the same rule applies, with the field path of the payment
        $this->sell(null, '1', '26000', true, [['method' => 'OTHER', 'amount' => '26000']])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_REFERENCE_REQUIRED')->assertJsonPath('error.details.field', 'payments.0.reference');
        // existing methods are unchanged (no description needed)
        $this->pay($sale['id'], '50000', 'QRIS')->assertCreated();
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.outstanding', '100000.0000');
        $this->get_('payments?method=OTHER')->assertJsonPath('meta.total', 2);
        $this->get_('dashboard')->assertJsonPath('data.payments.by_method.other.received', '100000.0000');
        $this->assertSame(['CASH', 'BANK_TRANSFER', 'QRIS', 'OTHER'], $this->get_('config')->json('data.payment_methods'));
        $this->get_("sales/{$sale['id']}/print")->assertOk();
    }

    public function test_database_refuses_an_undescribed_other_payment_and_keeps_old_methods_valid(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-DB', 7);
        $sale = $this->sell($abc, '10', '25000', true)->json('data');
        $paid = $this->pay($sale['id'], '1000', 'BANK_TRANSFER')->assertCreated()->json('data');
        $row = (array) DB::table('payments')->where('id', $paid['id'])->first();

        try {
            DB::transaction(function () use ($row): void {
                DB::table('payments')->insert(['id' => '01990000-0000-7000-8000-00000000f4b1', 'number' => 'PAY-TEST-OTHER', 'method' => 'OTHER', 'reference' => null, 'notes' => ' '] + $row);
            });
            $this->fail('An OTHER payment without description must be refused by the database.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode(), $e->getMessage());
            $this->assertStringContainsString('pay_other_described_check', $e->getMessage());
        }
        try {
            DB::transaction(function () use ($row): void {
                DB::table('payments')->insert(['id' => '01990000-0000-7000-8000-00000000f4b2', 'number' => 'PAY-TEST-BAD', 'method' => 'CHEQUE'] + $row);
            });
            $this->fail('An unknown method must be refused by the database.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('pay_method_check', $e->getMessage());
        }
        // money integrity is still guarded at commit (A4 deferred trigger raises on COMMIT)
        try {
            DB::transaction(fn () => DB::table('sales')->where('id', $sale['id'])->update(['paid_amount' => '99999']));
            $this->fail('A tampered paid_amount must be refused.');
        } catch (QueryException|\PDOException $e) {
            $this->assertStringContainsString('SALE_MONEY', $e->getMessage());
        }
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.paid', '1000.0000');
    }

    public function test_corrections_need_their_permission_and_owner_keeps_them(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-PERM', 7);
        $sale = $this->sell($abc, '10', '25000', true, [$this->cash('100000')])->json('data');
        $paymentId = DB::table('payments')->where('sale_id', $sale['id'])->value('id');
        $lineId = $sale['lines'][0]['id'];
        $returnBody = ['reason' => 'pecah', 'lines' => [['sale_line_id' => $lineId, 'quantity' => '1', 'disposition' => 'RETURN_TO_QUARANTINE']]];

        config(['dgway.authorization.role_permissions.OWNER' => []]); // an active operator without any permission
        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'salah input', 'reverse_payments' => true])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'salah input'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->cmd('POST', "sales/{$sale['id']}/returns", $returnBody)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED');
        $this->assertSame(0, DB::table('payment_reversals')->count());
        $this->assertSame(0, DB::table('customer_returns')->count());
        $this->assertSame('90.0000', $this->stock()['available']);
        // routine work is unaffected
        $this->pay($sale['id'], '1000')->assertCreated();

        config(['dgway.authorization.role_permissions.OWNER' => ['sales.return']]); // exactly one permission
        $this->cmd('POST', "sales/{$sale['id']}/returns", $returnBody)->assertCreated();
        $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'salah input'])->assertStatus(403);

        config(['dgway.authorization.role_permissions.OWNER' => ['*']]);
        $this->cmd('POST', "payments/{$paymentId}/reverse", ['reason' => 'salah input'])->assertOk();
        $sale2 = $this->sell(null, '2', '26000', true, [$this->cash('52000')])->json('data');
        $this->cmd('POST', "sales/{$sale2['id']}/reverse", ['reason' => 'salah input', 'reverse_payments' => true])->assertOk()->assertJsonPath('data.status', 'REVERSED');
        $this->assertLedgerConsistent();
    }

    public function test_idempotent_replay_never_duplicates_stock_or_payment(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-IDEM', 7);
        $body = ['customer_id' => $abc, 'node_id' => $this->node, 'confirm' => true,
            'lines' => [['product_id' => $this->egg, 'quantity' => '20', 'unit_price' => '25000']],
            'payments' => [['method' => 'BANK_TRANSFER', 'amount' => '200000']]];
        $first = $this->cmd('POST', 'sales', $body, 'f4b-sale-key-0001')->assertCreated()->json('data');
        $again = $this->cmd('POST', 'sales', $body, 'f4b-sale-key-0001')->assertCreated();
        $again->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first['id'], $again->json('data.id'));
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, $this->saleMovements($first['id']));
        $this->assertSame(1, DB::table('payments')->count());

        $changed = $body;
        $changed['lines'][0]['quantity'] = '21';
        $this->cmd('POST', 'sales', $changed, 'f4b-sale-key-0001')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->pay($first['id'], '300000', 'CASH', 'f4b-pay-key-0001')->assertCreated();
        $this->pay($first['id'], '300000', 'CASH', 'f4b-pay-key-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(2, DB::table('payments')->count());
        $this->pay($first['id'], '1', 'CASH', 'f4b-pay-key-0001')->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->get_("sales/{$first['id']}")->assertJsonPath('data.outstanding', '0.0000')->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame('80.0000', $this->stock()['available']);
    }

    /** Race: a payment arrives while the sale is being reversed → the payment waits, then is refused. */
    public function test_payment_racing_a_sale_reversal_waits_and_is_refused(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-RACE', 7);
        $sale = $this->sell($abc, '20', '25000', true, [$this->cash('200000', 'BANK_TRANSFER')])->json('data');
        $w = new WorkerProcess('hold-reverse-sale', [$this->owner->id, $sale['id'], '1000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->pay($sale['id'], '300000');
        $waited = (microtime(true) - $t) * 1000;
        $this->assertSame('DONE', $w->waitFor('DONE'));
        $this->assertSame(0, $w->finish());

        $this->assertGreaterThan(500, $waited, 'the payment waited for the sale lock');
        $res->assertStatus(409);
        $this->assertContains($res->json('error.code'), ['SALE_NOT_PAYABLE', 'INVALID_STATUS_TRANSITION']);
        $detail = $this->get_("sales/{$sale['id']}")->assertJsonPath('data.status', 'REVERSED')->json('data');
        $this->assertSame('0.0000', $detail['outstanding']);
        $this->assertSame(1, DB::table('payments')->count(), 'no payment was added to a reversed sale');
        $this->assertSame(1, DB::table('payment_reversals')->count(), 'the earlier payment was reversed explicitly, not deleted');
        $this->assertSame('100.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }

    /** Race: a reversal arrives while a payment is being recorded → the reversal waits and reverses both payments. */
    public function test_sale_reversal_racing_a_payment_reverses_every_payment(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-RACE-2', 7);
        $sale = $this->sell($abc, '20', '25000', true, [$this->cash('200000', 'BANK_TRANSFER')])->json('data');
        $w = new WorkerProcess('hold-payment', [$this->owner->id, $sale['id'], '300000', '1000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'salah customer', 'reverse_payments' => true]);
        $waited = (microtime(true) - $t) * 1000;
        $this->assertSame('DONE', $w->waitFor('DONE'));
        $this->assertSame(0, $w->finish());

        $this->assertGreaterThan(500, $waited);
        $res->assertOk()->assertJsonPath('data.status', 'REVERSED')->assertJsonPath('data.outstanding', '0.0000');
        $this->assertSame(2, DB::table('payments')->count(), 'payment history is kept');
        $this->assertSame(2, DB::table('payment_reversals')->count(), 'both payments reversed, none silently dropped');
        $this->assertSame('100.0000', $this->stock()['available']);
        $this->assertLedgerConsistent();
    }
}
