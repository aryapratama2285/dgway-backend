<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;

final class OwnerQueriesTest extends SalesTestCase
{
    public function test_dashboard_sales_payments_receivables_and_search(): void
    {
        $this->stockUp('300');
        $abc = $this->customer('CUS-ABC', 7);
        $xyz = $this->customer('CUS-XYZ', 14);
        $cash = $this->sell(null, '30', '26000', true, [$this->cash('780000')])->json('data');
        $b = $this->sell($abc, '100', '25000', true, [$this->cash('1500000', 'BANK_TRANSFER')])->json('data');
        $this->pay($b['id'], '700000', 'QRIS')->assertCreated();
        $this->sell($xyz, '10', '25500', true)->assertCreated();
        $this->sell(null, '1', '26000', false); // draft: not revenue
        // yesterday's sale must not appear today
        $this->cmd('POST', 'sales', ['node_id' => $this->node, 'business_date' => '2026-10-02', 'confirm' => true,
            'lines' => [['product_id' => $this->egg, 'quantity' => '1', 'unit_price' => '26000']], 'payments' => [['method' => 'CASH', 'amount' => '26000', 'business_date' => '2026-10-02']]])->assertCreated();

        $d = $this->get_('reports/daily')->assertOk()->json('data');
        $this->assertSame(3, $d['sales']['count']);
        $this->assertSame(1, $d['sales']['walk_in_count']);
        $this->assertSame('3535000.0000', $d['sales']['grand_total']);       // 780k + 2.5M + 255k
        $this->assertSame(1, $d['sales']['draft_count']);
        $this->assertSame('2980000.0000', $d['payments']['received']);       // 780k + 1.5M + 700k
        $this->assertSame('780000.0000', $d['payments']['by_method']['CASH']['received']);
        $this->assertSame('1500000.0000', $d['payments']['by_method']['BANK_TRANSFER']['received']);
        $this->assertSame('700000.0000', $d['payments']['by_method']['QRIS']['received']);
        $this->assertSame('555000.0000', $d['receivables']['total']);        // 300k + 255k
        $this->get_('reports/daily?date=2026-10-02')->assertJsonPath('data.sales.count', 1)->assertJsonPath('data.payments.received', '26000.0000');
        $this->get_('reports/sales?date_from=2026-10-01&date_to=2026-10-03')->assertJsonPath('data.sales.count', 4);

        $p = $this->get_('reports/sales-by-product')->assertOk()->json('data.0');
        $this->assertSame('TELUR-STD', $p['sku']);
        $this->assertSame('140.0000', $p['quantity_sold']);
        $this->assertSame('3535000.0000', $p['revenue']);
        $this->assertSame('300.0000', $p['purchase']['received_quantity']); // raw acquisition data, no COGS policy
        $this->assertSame('23000.0000', $p['purchase']['avg_unit_cost']);

        $this->get_('sales?date_from=2026-10-03&date_to=2026-10-03&status=COMPLETED')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $cash['id']);
        $this->get_("sales?customer_id={$abc}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.payment_status', 'PARTIALLY_PAID');
        $this->get_('sales?payment_status=UNPAID')->assertJsonPath('meta.total', 1);
        $this->get_('sales?q='.substr($b['number'], -7))->assertJsonPath('data.0.number', $b['number']);
        $this->get_('sales?q=xyz')->assertJsonPath('meta.total', 1);
        $this->get_('sales?walk_in=1')->assertJsonPath('meta.total', 3);
        $this->get_('sales?per_page=2&page=3')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->get_('payments?date_from=2026-10-03&date_to=2026-10-03')->assertJsonPath('meta.total', 3);
        $this->get_('payments?method=QRIS')->assertJsonPath('data.0.amount', '700000.0000')->assertJsonPath('data.0.sale_number', $b['number']);

        $c = $this->get_('receivables/customers')->assertOk()->json();
        $this->assertSame(2, $c['meta']['total']);
        $this->assertSame('CUS-ABC', $c['data'][0]['customer']['code']); // largest first
        $this->assertSame('300000.0000', $c['data'][0]['outstanding']);
        $this->get_('receivables/customers?q=xyz')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.outstanding', '255000.0000');
        $this->get_("receivables?customer_id={$abc}")->assertJsonPath('data.0.sale_number', $b['number'])->assertJsonPath('data.0.due_date', '2026-10-10');
    }

    public function test_receivable_queries_use_the_open_sales_index(): void
    {
        $plan = collect(DB::select("EXPLAIN SELECT sum(outstanding) FROM sales WHERE outstanding > 0 AND due_date < '2026-10-03'"))->pluck('QUERY PLAN')->implode("\n");
        // On a tiny table the planner may still choose a seq scan; force the comparison:
        DB::statement('SET enable_seqscan = off');
        try {
            $forced = collect(DB::select("EXPLAIN SELECT sum(outstanding) FROM sales WHERE outstanding > 0 AND due_date < '2026-10-03'"))->pluck('QUERY PLAN')->implode("\n");
            // A5-B fix (flaky in the full suite): on a near-empty table both partial
            // indexes cost the same and the choice depends on accumulated statistics.
            // Hide the competing index inside a rolled-back transaction so the plan
            // proves the customer index serves the query, deterministically.
            DB::beginTransaction();
            try {
                DB::statement('DROP INDEX sales_open_due_idx');
                $byCustomer = collect(DB::select("EXPLAIN SELECT sum(outstanding) FROM sales WHERE customer_id = '01928a3c-0000-7000-8000-000000000001' AND outstanding > 0"))->pluck('QUERY PLAN')->implode("\n");
            } finally {
                DB::rollBack();
            }
        } finally {
            DB::statement('RESET enable_seqscan');
        }
        $this->assertStringContainsString('sales_open_customer_idx', $byCustomer);
        $this->assertStringContainsString('sales_open_due_idx', $forced, $plan);
    }
}
