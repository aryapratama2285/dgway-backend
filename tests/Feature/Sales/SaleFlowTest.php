<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;

final class SaleFlowTest extends SalesTestCase
{
    public function test_walk_in_cash_sale_takes_stock_and_completes(): void
    {
        $this->stockUp('140');
        $res = $this->sell(null, '30', '26000', true, [$this->cash('780000')])->assertCreated()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.is_walk_in', true)
            ->assertJsonPath('data.grand_total', '780000.0000')
            ->assertJsonPath('data.paid', '780000.0000')
            ->assertJsonPath('data.outstanding', '0.0000')
            ->assertJsonPath('data.payment_status', 'PAID');
        $this->assertMatchesRegularExpression('/^SAL-261003-\d{3}$/', $res->json('data.number'));
        $this->assertSame('110.0000', $this->stock()['available']);

        $m = DB::table('stock_movements')->where('movement_type', 'SALE')->first();
        $this->assertSame(-1, (int) $m->direction);
        $this->assertEquals(30, (float) $m->quantity);
        $this->assertSame('sale', $m->source_document_type);
        $this->assertSame($res->json('data.id'), $m->source_document_id);
        $this->assertNull($m->unit_cost);
        $this->assertLedgerConsistent();
    }

    public function test_b2b_credit_sale_with_partial_payments(): void
    {
        $this->stockUp('200');
        $abc = $this->customer('CUS-CATERING-ABC', 7);
        $sale = $this->sell($abc, '100', '25500', true, [$this->cash('1500000', 'BANK_TRANSFER')])->assertCreated()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.grand_total', '2550000.0000')
            ->assertJsonPath('data.outstanding', '1050000.0000')
            ->assertJsonPath('data.payment_status', 'PARTIALLY_PAID')
            ->assertJsonPath('data.due_date', '2026-10-10')
            ->json('data');

        $this->pay($sale['id'], '700000', 'QRIS')->assertCreated()->assertJsonPath('data.sale.outstanding', '350000.0000');
        $this->pay($sale['id'], '350000.01')->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_EXCEEDS_OUTSTANDING');
        $this->pay($sale['id'], '350000')->assertCreated()->assertJsonPath('data.sale.status', 'COMPLETED')->assertJsonPath('data.sale.outstanding', '0.0000');
        $this->pay($sale['id'], '1')->assertStatus(409)->assertJsonPath('error.code', 'SALE_NOT_PAYABLE');

        $detail = $this->get_("sales/{$sale['id']}")->assertOk()->json('data');
        $this->assertCount(3, $detail['payments']);
        $this->assertSame(['BANK_TRANSFER', 'QRIS', 'CASH'], array_column($detail['payments'], 'method'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sale.completed')->count());
    }
}
