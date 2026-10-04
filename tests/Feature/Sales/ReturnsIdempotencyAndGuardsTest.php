<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReturnsIdempotencyAndGuardsTest extends SalesTestCase
{
    private function returnLines(array $sale, string $qty, string $disposition = 'RETURN_TO_AVAILABLE'): array
    {
        return [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => $qty, 'disposition' => $disposition]];
    }

    public function test_customer_return_dispositions_and_limits(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '30', '26000', true, [$this->cash('300000')])->json('data'); // 780k, owes 480k
        $this->assertSame('70.0000', $this->stock()['available']);

        $r1 = $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'kelebihan kirim', 'lines' => $this->returnLines($sale, '5')])->assertCreated()
            ->assertJsonPath('data.total_value', '130000.0000')->assertJsonPath('data.credit_amount', '130000.0000')->assertJsonPath('data.refund_amount', '0.0000')
            ->assertJsonPath('data.sale.outstanding', '350000.0000')->assertJsonPath('data.sale.lines.0.returnable_quantity', '25.0000')->json('data');
        $this->assertMatchesRegularExpression('/^RET-261003-\d{3}$/', $r1['number']);
        $this->assertSame('75.0000', $this->stock()['available']);

        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'pecah', 'lines' => $this->returnLines($sale, '3', 'LOSS')])->assertCreated();
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'retak', 'lines' => $this->returnLines($sale, '2', 'RETURN_TO_QUARANTINE')])->assertCreated();
        $s = $this->stock();
        $this->assertSame('75.0000', $s['available'], 'broken / doubtful eggs are not sellable stock');
        $this->assertSame('2.0000', $s['quarantine']);
        $this->assertEquals(10, (float) DB::table('stock_movements')->where('movement_type', 'CUSTOMER_RETURN')->sum('quantity'));
        $this->assertEquals(3, (float) DB::table('stock_movements')->where('movement_type', 'LOSS')->where('source_document_type', 'customer_return')->sum('quantity'));

        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'x', 'lines' => $this->returnLines($sale, '20.001')])->assertStatus(409)->assertJsonPath('error.code', 'RETURN_EXCEEDS_SOLD');
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.outstanding', '220000.0000')->assertJsonPath('data.credited', '260000.0000');

        // returning more value than still owed → refund, which needs a method
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'x', 'lines' => $this->returnLines($sale, '10')])->assertStatus(422)->assertJsonPath('error.code', 'REFUND_METHOD_REQUIRED');
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'x', 'refund_method' => 'CASH', 'lines' => $this->returnLines($sale, '10')])->assertCreated()
            ->assertJsonPath('data.credit_amount', '220000.0000')->assertJsonPath('data.refund_amount', '40000.0000')->assertJsonPath('data.sale.status', 'COMPLETED');
        $this->get_('reports/daily')->assertJsonPath('data.payments.refunded', '40000.0000')->assertJsonPath('data.sales.returns_value', '520000.0000');
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'customer_return.recorded')->count());
        $this->assertLedgerConsistent();
    }

    public function test_retries_never_duplicate_sale_payment_stock_or_return(): void
    {
        $this->stockUp('140');
        $abc = $this->customer('CUS-ABC', 7);
        $k = (string) Str::uuid();
        $first = $this->sell($abc, '100', '25000', true, [$this->cash('1500000')], $k)->assertCreated();
        $again = $this->sell($abc, '100', '25000', true, [$this->cash('1500000')], $k)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $again->json());
        $sale = $first->json('data');

        $pk = (string) Str::uuid();
        $this->pay($sale['id'], '700000', 'CASH', $pk)->assertCreated()->assertJsonPath('data.sale.outstanding', '300000.0000');
        $this->pay($sale['id'], '700000', 'CASH', $pk)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.sale.outstanding', '300000.0000');

        $rk = (string) Str::uuid();
        $body = ['reason' => 'x', 'lines' => $this->returnLines($sale, '4')];
        $this->cmd('POST', "sales/{$sale['id']}/returns", $body, $rk)->assertCreated();
        $this->cmd('POST', "sales/{$sale['id']}/returns", $body, $rk)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $payId = DB::table('payments')->where('amount', 700000)->value('id');
        $vk = (string) Str::uuid();
        $this->cmd('POST', "payments/{$payId}/reverse", ['reason' => 'salah'], $vk)->assertOk();
        $this->cmd('POST', "payments/{$payId}/reverse", ['reason' => 'salah'], $vk)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(2, DB::table('payments')->count());
        $this->assertSame(1, DB::table('payment_reversals')->count());
        $this->assertSame(1, DB::table('customer_returns')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame('44.0000', $this->stock()['available']); // 140 − 100 + 4
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.outstanding', '900000.0000'); // 2.5M − 1.5M − 100k credit + 0 (700k reversed)
    }

    public function test_master_data_guard_for_new_sales(): void
    {
        $this->stockUp();
        $abc = $this->customer('CUS-ABC', 7);
        $pickup = $this->node('PICKUP-1', ['PICKUP']);
        $this->cmd('POST', 'sales', ['node_id' => $pickup, 'lines' => [['product_id' => $this->egg, 'quantity' => '1', 'unit_price' => '1']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');
        $this->sell($this->supplier, '1', '1', false)->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_NOT_USABLE');
        $l = ['product_id' => $this->egg, 'quantity' => '1', 'uom' => 'PCS', 'unit_price' => '1'];
        $this->cmd('POST', 'sales', ['node_id' => $this->node, 'lines' => [$l]])->assertStatus(422)->assertJsonPath('error.code', 'UOM_NOT_BASE');

        $draft = $this->sell($abc, '1', '25000', false)->json('data');
        $done = $this->sell($abc, '1', '25000', true)->json('data');
        $this->cmd('POST', "business-partners/{$abc}/roles/customer/deactivate")->assertOk();
        $this->sell($abc, '1', '1', false)->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_NOT_USABLE');
        $this->cmd('POST', "sales/{$draft['id']}/confirm")->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_NOT_USABLE');
        $this->pay($done['id'], '25000')->assertCreated(); // collecting old receivables still works
        $this->get_("sales/{$done['id']}")->assertOk()->assertJsonPath('data.customer.code', 'CUS-ABC'); // history readable

        $this->cmd('POST', "products/{$this->egg}/deactivate")->assertOk();
        $this->sell(null, '1', '1', false)->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');
    }

    public function test_audit_events(): void
    {
        $this->stockUp();
        $abc = $this->customer('CUS-ABC', 7);
        $sale = $this->sell($abc, '10', '25000', true, [$this->cash('100000')])->json('data');
        $this->pay($sale['id'], '150000')->assertCreated();
        $payId = DB::table('payments')->orderBy('created_at')->value('id');
        $this->cmd('POST', "payments/{$payId}/reverse", ['reason' => 'x'])->assertOk();
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'x', 'lines' => $this->returnLines($sale, '1')])->assertCreated();
        $s2 = $this->sell(null, '1', '26000', true, [$this->cash('26000')])->json('data');
        $this->cmd('POST', "sales/{$s2['id']}/reverse", ['reason' => 'salah', 'reverse_payments' => true])->assertOk();
        $d = $this->sell(null, '1', '26000', false)->json('data');
        $this->cmd('POST', "sales/{$d['id']}/cancel", ['reason' => 'batal'])->assertOk();

        $counts = DB::table('audit_logs')->selectRaw('action, count(*) n')->groupBy('action')->pluck('n', 'action')->all();
        foreach (['sale.created' => 3, 'sale.confirmed' => 2, 'sale.completed' => 2, 'sale.cancelled' => 1, 'sale.reversed' => 1,
            'payment.recorded' => 3, 'payment.reversed' => 2, 'customer_return.recorded' => 1, 'inventory.sale' => 2] as $action => $n) {
            $this->assertSame($n, (int) ($counts[$action] ?? 0), $action);
        }
    }
}
