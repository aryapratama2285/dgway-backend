<?php

declare(strict_types=1);

namespace Tests\Feature\Printing;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

/** A5-C §4 — semantic, device-neutral sale receipt; every figure comes from the backend. */
final class ReceiptPrintTest extends SalesTestCase
{
    private function receipt(string $saleId, string $query = ''): array
    {
        return $this->get_("sales/{$saleId}/print{$query}")->assertOk()->json('data');
    }

    public function test_walk_in_retail_receipt(): void
    {
        $this->stockUp('140');
        $sale = $this->sell(null, '30', '27000', true, [$this->cash('810000')])->json('data');
        $r = $this->receipt($sale['id']);

        $this->assertSame([1, 'RECEIPT'], [$r['schema_version'], $r['document_type']]);
        $this->assertSame(['business_name' => 'DG WAY', 'business_subtitle' => 'Supply & Distribution', 'address' => null, 'phone' => null, 'footer_text' => null], $r['business']);
        $this->assertSame(['medium' => 'RECEIPT', 'paper_width_mm' => 80, 'supported_widths_mm' => [58, 80]], $r['layout']);
        $d = $r['document'];
        $this->assertSame([$sale['id'], $sale['number'], 'COMPLETED', true, null], [$d['sale_id'], $d['number'], $d['status'], $d['is_final'], $d['notice']]);
        $this->assertTrue($d['is_walk_in']);
        $this->assertNull($d['customer'], 'no corporate/customer block for a walk-in');
        $this->assertNull($d['payment_terms']);
        $this->assertSame('Owner Satu', $d['operator']['name']);
        $this->assertSame(['line_no' => 1, 'sku' => 'TELUR-STD', 'name' => 'Produk TELUR-STD'], array_intersect_key($d['lines'][0], array_flip(['line_no', 'sku', 'name'])));
        $this->assertSame(['value' => '30.0000', 'formatted' => '30', 'uom' => 'KG'], $d['lines'][0]['quantity']);
        $this->assertSame(['amount' => '27000.0000', 'formatted' => 'Rp27.000'], $d['lines'][0]['unit_price']);
        $this->assertSame(['amount' => '810000.0000', 'formatted' => 'Rp810.000'], $d['lines'][0]['line_total']);
        $this->assertSame('Rp810.000', $d['totals']['grand_total']['formatted']);
        $this->assertSame(['810000.0000', '0.0000'], [$d['totals']['total_paid']['amount'], $d['totals']['outstanding']['amount']]);
        $this->assertSame([['CASH', 'Tunai', '810000.0000', false]], array_map(fn ($p) => [$p['method'], $p['method_label'], $p['amount']['amount'], $p['reversed']], $d['payments']));
        $this->assertSame("dgway://sale/{$sale['id']}", $d['reference']['qr_payload']);
        $this->assertNotNull($d['transaction_at']);
    }

    public function test_b2b_partial_payment_with_every_method_and_outstanding(): void
    {
        $this->stockUp('140');
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('500000', 'BANK_TRANSFER'), $this->cash('175000', 'QRIS')])->json('data');
        $this->pay($sale['id'], '100000', 'CASH')->assertCreated();
        $d = $this->receipt($sale['id'])['document'];

        $this->assertFalse($d['is_walk_in']);
        $this->assertSame(['code' => 'CUS-CATERING-ABC', 'name' => 'Customer CUS-CATERING-ABC'], $d['customer'], 'identity only: no phone/address/credit limit');
        $this->assertSame(['term_days' => 7, 'due_date' => '2026-10-10'], $d['payment_terms']);
        $this->assertSame('CONFIRMED', $d['status']);
        $this->assertSame([['BANK_TRANSFER', 'Transfer Bank', '500000.0000'], ['QRIS', 'QRIS', '175000.0000'], ['CASH', 'Tunai', '100000.0000']],
            array_map(fn ($p) => [$p['method'], $p['method_label'], $p['amount']['amount']], $d['payments']));
        $this->assertSame(['1275000.0000', '775000.0000', '500000.0000'], [$d['totals']['grand_total']['amount'], $d['totals']['total_paid']['amount'], $d['totals']['outstanding']['amount']]);
        $this->assertSame(['Rp1.275.000', 'Rp775.000', 'Rp500.000'], [$d['totals']['grand_total']['formatted'], $d['totals']['total_paid']['formatted'], $d['totals']['outstanding']['formatted']]);
        $json = json_encode($d);
        foreach (['credit_limit', 'available_credit', 'phone', '+62'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_payment_reversal_and_sale_reversal_and_cancellation_are_represented(): void
    {
        $this->stockUp('140');
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '10', '25500', true, [$this->cash('100000', 'QRIS')])->json('data');
        $payment = (string) DB::table('payments')->where('sale_id', $sale['id'])->value('id');
        $this->cmd('POST', "payments/{$payment}/reverse", ['reason' => 'salah input'])->assertOk();
        $d = $this->receipt($sale['id'])['document'];
        $this->assertTrue($d['payments'][0]['reversed'], 'the reversed payment stays on the receipt, flagged');
        $this->assertSame(['0.0000', '255000.0000'], [$d['totals']['total_paid']['amount'], $d['totals']['outstanding']['amount']], 'totals exclude it — computed by the domain');

        $this->cmd('POST', "sales/{$sale['id']}/reverse", ['reason' => 'batal'])->assertOk();
        $d = $this->receipt($sale['id'])['document'];
        $this->assertSame(['REVERSED', false, 'REVERSED'], [$d['status'], $d['is_final'], $d['notice']['code']]);
        $this->assertSame('TRANSAKSI DIBATALKAN (REVERSAL)', $d['notice']['text']);
        $this->assertNotNull($d['reversed_at']);

        $draft = $this->sell(null, '1', '27000', false)->json('data');
        $this->assertSame('DRAFT', $this->receipt($draft['id'])['document']['notice']['code']);
        $this->cmd('POST', "sales/{$draft['id']}/cancel", ['reason' => 'x'])->assertOk();
        $this->assertSame(['CANCELLED', 'DIBATALKAN'], [$this->receipt($draft['id'])['document']['notice']['code'], $this->receipt($draft['id'])['document']['notice']['text']]);
    }

    public function test_exact_money_and_quantity_representation(): void
    {
        $this->stockUp('140');
        $sale = $this->sell(null, '1.235', '27000', true, [$this->cash('33344.5')], null, ['discount' => '0.5'])->assertCreated()->json('data');
        $d = $this->receipt($sale['id'])['document'];
        $line = $d['lines'][0];
        $this->assertSame(['value' => '1.2350', 'formatted' => '1,235', 'uom' => 'KG'], $line['quantity']);
        $this->assertSame(['amount' => '0.5000', 'formatted' => 'Rp0,5'], $line['discount']);
        $this->assertSame(['amount' => '33344.5000', 'formatted' => 'Rp33.344,5'], $line['line_total'], 'exact stored decimal, never re-rounded by the printer');
        $this->assertSame($d['totals']['grand_total']['amount'], DB::table('sales')->where('id', $sale['id'])->value('grand_total'));
    }

    public function test_receipt_width_can_be_requested_per_print_and_is_validated(): void
    {
        $this->stockUp('10');
        $sale = $this->sell(null, '1', '27000', true, [$this->cash('27000')])->json('data');
        $this->assertSame(58, $this->receipt($sale['id'], '?width=58')['layout']['paper_width_mm']);
        $this->assertSame(80, $this->receipt($sale['id'], '?width=80')['layout']['paper_width_mm']);
        $this->get_("sales/{$sale['id']}/print?width=72")->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->get_('sales/'.\Illuminate\Support\Str::uuid().'/print')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
