<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Sales\SalesTestCase;

/** A5-B §17 — owner dashboard, daily activity, search, operational defaults, Asia/Jakarta business dates. */
final class OperationsApiTest extends SalesTestCase
{
    public function test_dashboard_summarises_one_business_date_without_hpp(): void
    {
        $po = $this->cmd('POST', 'purchase-orders', ['supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => '150', 'unit_cost' => '23000']]])->json('data');
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk();
        $this->receive([$this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']])]);
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $this->sell(null, '30', '27000', true, [$this->cash('810000')])->assertCreated();
        $this->sell($abc, '50', '25500', true, [$this->cash('500000', 'BANK_TRANSFER'), $this->cash('275000', 'QRIS')])->assertCreated();

        $d = $this->get_('dashboard?date=2026-10-03')->assertOk()->json('data');
        $this->assertSame(['2026-10-03', 'Asia/Jakarta', 'IDR'], [$d['business_date'], $d['timezone'], $d['currency']]);
        $this->assertSame([2, '2085000.0000'], [$d['sales']['count'], $d['sales']['total']]);
        $this->assertSame('1585000.0000', $d['payments']['total']);
        $this->assertSame(['810000.0000', '500000.0000', '275000.0000'],
            [$d['payments']['by_method']['cash']['received'], $d['payments']['by_method']['bank_transfer']['received'], $d['payments']['by_method']['qris']['received']]);
        $this->assertSame(['500000.0000', '500000.0000', '0.0000', '0.0000'],
            [$d['receivables']['total'], $d['receivables']['not_yet_due'], $d['receivables']['due_today'], $d['receivables']['overdue']]);
        $this->assertSame([1, '3450000.0000'], [$d['purchasing']['purchase_orders']['count'], $d['purchasing']['purchase_orders']['total']]);
        $this->assertSame(['150.0000', '140.0000', '10.0000'],
            [$d['purchasing']['received_quantity'][0]['received'], $d['purchasing']['received_quantity'][0]['accept'], $d['purchasing']['received_quantity'][0]['loss']]);
        $this->assertSame('60.0000', $d['inventory']['stock_by_uom'][0]['available']);
        $this->assertSame(['10.0000', '80.0000', 1], [$d['inventory']['losses_on_date'], $d['inventory']['sold_quantity_on_date'], $d['inventory']['active_lots_now']]);
        $this->assertStringNotContainsStringIgnoringCase('hpp', json_encode($d));
        $this->assertStringNotContainsStringIgnoringCase('cogs', json_encode($d));

        // receivables as of today equal the live A4 receivable summary
        $this->assertSame($this->get_('receivables/summary')->json('data.total'), $d['receivables']['total']);
        // an empty day is all zeros, not an error
        $this->get_('dashboard?date=2026-09-01')->assertOk()->assertJsonPath('data.sales.count', 0)->assertJsonPath('data.receivables.total', '0.0000');
        $this->get_('dashboard?date=03-10-2026')->assertStatus(422);
    }

    public function test_activity_lists_the_days_events_and_receivable_change(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('775000')])->json('data');
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '2', 'reason' => 'pecah'])->assertCreated();
        $payment = (string) DB::table('payments')->where('sale_id', $sale['id'])->value('id');
        $this->cmd('POST', "payments/{$payment}/reverse", ['reason' => 'salah input'])->assertOk();

        $res = $this->get_('activity?date=2026-10-03&per_page=100')->assertOk();
        $types = array_count_values(array_column($res->json('data'), 'type'));
        ksort($types);
        $this->assertSame(['GOODS_RECEIPT' => 1, 'LOSS' => 1, 'PAYMENT' => 1, 'PAYMENT_REVERSAL' => 1, 'SALE' => 1], $types);
        $this->assertSame(['0.0000', '1275000.0000', '1275000.0000'], array_values($res->json('summary.receivable_change')));
        $this->assertSame(1, $this->get_('activity?date=2026-10-03&type=SALE')->json('meta.total'));
        $this->get_('activity?type=NOPE')->assertStatus(422);
    }

    public function test_search_products_partners_and_documents_paginated(): void
    {
        $this->stockUp('100');
        $abc = $this->customer('CUS-CATERING-ABC', 7);
        $sale = $this->sell($abc, '5', '25500', true, [$this->cash('127500')])->json('data');
        $phone = (string) DB::table('business_partners')->where('id', $abc)->value('phone'); // +62812…
        $local = '0'.substr($phone, 3);

        $this->assertSame(['product'], array_column($this->get_('search?q=telur-std')->json('data'), 'type'));
        $this->assertSame([$abc], array_column($this->get_("search?q={$local}&type=partners")->json('data'), 'id'), 'local 08… phone matches +628…');
        $this->assertSame([$abc], array_column($this->get_('search?q=catering&type=partners')->json('data'), 'id'));
        $this->assertSame(['SUP-BANDUNG'], array_column($this->get_('search?q=sup-band&type=partners')->json('data'), 'code'));
        $this->assertSame([$sale['id']], array_column($this->get_("search?q={$sale['number']}&type=documents")->json('data'), 'id'));
        $this->assertSame(['inventory_lot'], array_column($this->get_('search?q=LOT-261003&type=documents')->json('data'), 'type'));
        $this->sell(null, '1', '27000', true, [$this->cash('27000')])->assertCreated();
        $this->sell(null, '1', '27000', true, [$this->cash('27000')])->assertCreated();
        $page = $this->get_('search?q=sal-26&per_page=2&type=documents')->assertOk()->json(); // document numbers match by prefix
        $this->assertSame(['sale', 'sale'], array_column($page['data'], 'type'));
        $this->assertCount(2, $page['data']);
        $this->assertGreaterThan(2, $page['meta']['total']);
        $this->get_('search?q=a')->assertStatus(422);
    }

    public function test_config_serves_operational_defaults_instead_of_hard_coded_ids(): void
    {
        $c = $this->get_('config')->assertOk()->json('data');
        $this->assertSame(['IDR', 'Asia/Jakarta', '2026-10-03'], [$c['currency'], $c['business_timezone'], $c['business_date']]);
        $this->assertSame(['id' => $this->node, 'code' => 'DG-WAY-PRIMARY', 'name' => 'DG-WAY-PRIMARY'], $c['default_node']);
        $this->assertNull($c['walk_in_customer'], 'created by dgway:bootstrap');
        $this->assertSame(['default_per_page' => 25, 'max_per_page' => 100], $c['pagination']);
        $this->assertSame(['CASH', 'BANK_TRANSFER', 'QRIS'], $c['payment_methods']);
        $this->assertSame('Idempotency-Key', $c['idempotency']['header']);
    }

    /** 23:59:59 WIB belongs to the same business day; 00:00:00 WIB to the next — regardless of the DB session timezone. */
    public function test_asia_jakarta_midnight_boundary(): void
    {
        $this->stockUp('100');
        DB::statement("SET TIME ZONE 'America/Los_Angeles'"); // a misconfigured DB session must not move transactions between days

        $this->clock->travelTo('2026-10-03T16:59:59Z'); // 2026-10-03 23:59:59 WIB
        $late = $this->sell(null, '10', '27000', true, [$this->cash('270000')])->json('data');
        $this->clock->travelTo('2026-10-03T17:00:00Z'); // 2026-10-04 00:00:00 WIB
        $early = $this->sell(null, '5', '27000', true, [$this->cash('135000')])->json('data');

        $this->assertSame(['2026-10-03', '2026-10-04'], [$late['business_date'], $early['business_date']]);
        $this->assertSame('2026-10-04', $this->get_('config')->json('data.business_date'));
        $this->get_('dashboard?date=2026-10-03')->assertJsonPath('data.sales.count', 1)->assertJsonPath('data.payments.total', '270000.0000');
        $this->get_('dashboard?date=2026-10-04')->assertJsonPath('data.sales.count', 1)->assertJsonPath('data.payments.total', '135000.0000');
        $this->get_('dashboard')->assertJsonPath('data.business_date', '2026-10-04');
        $this->assertSame([$late['id']], array_map(fn ($r) => $r['document']['id'], $this->get_('activity?date=2026-10-03&type=SALE')->json('data')));
        $this->assertSame([$early['id']], array_map(fn ($r) => $r['document']['id'], $this->get_('activity?date=2026-10-04&type=SALE')->json('data')));
        $this->assertSame(['2026-10-03', '2026-10-04'], DB::table('stock_movements')->where('movement_type', 'SALE')->orderBy('sequence')->pluck('business_date')->map(fn ($d) => (string) $d)->all());
    }
}
