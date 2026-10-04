<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Raw SQL against sales / payments / returns: the database refuses to corrupt financial history. */
final class SalesDatabaseConstraintsTest extends SalesTestCase
{
    private array $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockUp('100');
        $abc = $this->customer('CUS-ABC', 7);
        $this->sale = $this->sell($abc, '40', '25000', true, [$this->cash('400000')])->json('data'); // 1 000 000, paid 400 000
    }

    private function assertRejected(Closure $sql, string $sqlState, string $messagePart = ''): void
    {
        try {
            DB::transaction($sql); // deferred constraint triggers fire at this COMMIT
            $this->fail('The database accepted a forbidden statement.');
        } catch (QueryException|\PDOException $e) { // deferred triggers fail the COMMIT itself (PDOException)
            $this->assertSame($sqlState, $e->errorInfo[0] ?? $e->getCode(), $e->getMessage());
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }
        }
    }

    public function test_payments_are_append_only(): void
    {
        $id = DB::table('payments')->value('id');
        $this->assertRejected(fn () => DB::table('payments')->where('id', $id)->update(['amount' => 1]), '23001');
        $this->assertRejected(fn () => DB::table('payments')->where('id', $id)->delete(), '23001');
        $this->assertRejected(fn () => DB::statement('DELETE FROM payments'), '23001');
        $this->assertSame('400000.0000', (string) DB::table('payments')->value('amount'));
    }

    public function test_payment_reversals_are_unique_and_append_only(): void
    {
        $pay = DB::table('payments')->first();
        $this->cmd('POST', "payments/{$pay->id}/reverse", ['reason' => 'x'])->assertOk();
        $this->assertRejected(fn () => DB::table('payment_reversals')->insert(['id' => (string) Str::uuid7(), 'payment_id' => $pay->id, 'sale_id' => $pay->sale_id,
            'reason' => 'again', 'reversed_at' => now(), 'business_date' => '2026-10-03']), '23505');
        $this->assertRejected(fn () => DB::table('payment_reversals')->delete(), '23001');
    }

    public function test_cached_paid_amount_cannot_drift_from_payment_history(): void
    {
        // the CHECKs allow it, the deferred history check at COMMIT does not
        $this->assertRejected(fn () => DB::table('sales')->where('id', $this->sale['id'])->update(['paid_amount' => 500000]), '23514', 'SALE_MONEY');
        // inserting a payment without updating the sale is caught as well
        $this->assertRejected(fn () => DB::table('payments')->insert(['id' => (string) Str::uuid7(), 'number' => 'PAY-261003-900', 'sale_id' => $this->sale['id'],
            'method' => 'CASH', 'amount' => 1, 'currency' => 'IDR', 'paid_at' => now(), 'business_date' => '2026-10-03']), '23514', 'SALE_MONEY');
        $this->assertSame('400000.0000', (string) DB::table('sales')->where('id', $this->sale['id'])->value('paid_amount'));
    }

    public function test_outstanding_can_never_be_negative(): void
    {
        $this->assertRejected(fn () => DB::table('sales')->where('id', $this->sale['id'])->update(['paid_amount' => 1000001]), '23514');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $this->sale['id'])->update(['outstanding' => 0]), '428C9'); // generated
    }

    public function test_payment_amount_and_method_checks(): void
    {
        foreach ([['amount' => 0], ['amount' => -5], ['method' => 'BITCOIN']] as $i => $bad) {
            $this->assertRejected(fn () => DB::table('payments')->insert(array_replace(['id' => (string) Str::uuid7(), 'number' => "PAY-261003-80{$i}", 'sale_id' => $this->sale['id'],
                'method' => 'CASH', 'amount' => 1, 'currency' => 'IDR', 'paid_at' => now(), 'business_date' => '2026-10-03'], $bad)), '23514');
        }
    }

    public function test_confirmed_sale_is_immutable_and_undeletable(): void
    {
        $id = $this->sale['id'];
        $this->assertRejected(fn () => DB::table('sales')->where('id', $id)->update(['grand_total' => 1, 'subtotal' => 1]), '23001', 'immutable');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $id)->update(['customer_id' => null]), '23001');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $id)->delete(), '23001');
        $this->assertRejected(fn () => DB::table('sale_lines')->where('sale_id', $id)->update(['unit_price' => 1, 'line_subtotal' => 40, 'line_total' => 40]), '23001');
        $this->assertRejected(fn () => DB::table('sale_lines')->where('sale_id', $id)->delete(), '23001');
        $this->assertRejected(fn () => DB::table('sale_lines')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $id, 'line_no' => 9, 'product_id' => $this->egg,
            'uom_code' => 'KG', 'quantity' => 1, 'unit_price' => 1, 'line_subtotal' => 1, 'line_total' => 1]), '23001');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $id)->update(['status' => 'DRAFT', 'confirmed_at' => null, 'due_date' => null, 'payment_term_days' => null, 'paid_amount' => 0]), '23514');
    }

    public function test_line_and_status_checks(): void
    {
        $line = DB::table('sale_lines')->where('sale_id', $this->sale['id'])->first();
        $this->assertRejected(fn () => DB::table('sale_lines')->where('id', $line->id)->update(['returned_quantity' => 41]), '23514');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $this->sale['id'])->update(['status' => 'LOST']), '23514');
        // a draft with wrong header totals cannot be confirmed
        $draft = $this->sell(null, '2', '26000', false)->json('data');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $draft['id'])->update(['subtotal' => 1, 'grand_total' => 1]) + DB::table('sales')->where('id', $draft['id'])
            ->update(['status' => 'COMPLETED', 'confirmed_at' => now(), 'due_date' => '2026-10-03', 'payment_term_days' => 0]), '23514', 'SALE_TOTALS');
        $this->assertRejected(fn () => DB::table('sale_lines')->insert(['id' => (string) Str::uuid7(), 'sale_id' => $draft['id'], 'line_no' => 2, 'product_id' => $this->egg,
            'uom_code' => 'KG', 'quantity' => 3, 'unit_price' => 26000, 'line_subtotal' => 78001, 'line_total' => 78001]), '23514'); // subtotal ≠ round(q×p)
    }

    public function test_walk_in_sale_cannot_owe_even_through_sql(): void
    {
        $draft = $this->sell(null, '2', '26000', false)->json('data');
        $this->assertRejected(fn () => DB::table('sales')->where('id', $draft['id'])
            ->update(['status' => 'CONFIRMED', 'confirmed_at' => now(), 'due_date' => '2026-10-03', 'payment_term_days' => 0]), '23514', 'sales_walk_in_cash_check');
    }

    public function test_customer_returns_are_append_only_and_bounded(): void
    {
        $this->cmd('POST', "sales/{$this->sale['id']}/returns", ['reason' => 'x', 'lines' => [['sale_line_id' => $this->sale['lines'][0]['id'], 'quantity' => '1', 'disposition' => 'LOSS']]])->assertCreated();
        $this->assertRejected(fn () => DB::table('customer_returns')->update(['credit_amount' => 0]), '23001');
        $this->assertRejected(fn () => DB::table('customer_return_lines')->delete(), '23001');
        $this->assertRejected(fn () => DB::table('sale_lines')->where('sale_id', $this->sale['id'])->update(['returned_quantity' => 2]), '23514', 'SALE_MONEY'); // counter ≠ return history
    }
}
