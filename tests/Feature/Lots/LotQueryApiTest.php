<?php

declare(strict_types=1);

namespace Tests\Feature\Lots;

use Illuminate\Support\Facades\DB;

/** A5-B Part 11: lot read models — search, pagination, provenance, movements, lineage, destinations, no N+1. */
final class LotQueryApiTest extends LotTestCase
{
    public function test_lot_detail_shows_provenance_dispositions_remaining_and_derived_status(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [$gr, $lot] = $this->receiveOn('2026-10-03', '150', [
            ['type' => 'ACCEPT', 'quantity' => '130'], ['type' => 'LOSS', 'quantity' => '10'], ['type' => 'REGRADE', 'quantity' => '10', 'target_product_id' => $afkir],
        ], null, ['best_before_date' => '2026-10-20']);
        $number = DB::table('inventory_lots')->where('id', $lot)->value('number');

        foreach ([$lot, $number] as $ref) { // UUID or lot number
            $d = $this->get_("lots/{$ref}")->assertOk()->json('data');
            $this->assertSame($lot, $d['id']);
        }
        $this->assertSame('RECEIPT', $d['origin']);
        $this->assertSame('ACTIVE', $d['status']);
        $this->assertSame('150.0000', $d['original_quantity']);
        $this->assertSame(['on_hand' => '130.0000', 'available' => '130.0000', 'quarantine' => '0.0000', 'return_pending' => '0.0000'], $d['remaining'], 'every quantity has 4 decimals');
        $this->assertSame('SUP-BANDUNG', $d['supplier']['code']);
        $this->assertSame($gr, $d['goods_receipt']['id']);
        $this->assertSame('2026-10-20', $d['best_before_date']);
        $this->assertSame('RECORDED', $d['provenance']);
        $this->assertSame(['ACCEPT', 'LOSS', 'REGRADE'], array_column($d['dispositions'], 'type'));
        $this->assertSame('TELUR-AFKIR', $d['children'][0]['sku']);

        $lineage = $this->get_("lots/{$d['children'][0]['id']}/lineage")->assertOk()->json('data');
        $this->assertSame([$lot], array_column($lineage['ancestors'], 'id'));
        $this->assertSame([], $lineage['descendants']);
        $this->assertSame([$d['children'][0]['id']], array_column($this->get_("lots/{$lot}/lineage")->json('data.descendants'), 'id'));

        $this->get_('lots/LOT-999999-999')->assertNotFound();
    }

    public function test_movements_and_customer_destinations_net_of_returns(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '100');
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $this->sell(null, '30', '27000', true, [$this->cash('810000')])->assertCreated();
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('775000')])->assertCreated()->json('data');
        $this->cmd('POST', "sales/{$sale['id']}/returns", ['reason' => 'pecah', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => '4', 'disposition' => 'LOSS']]])->assertCreated();

        $moves = $this->get_("lots/{$lot}/movements?per_page=2")->assertOk();
        $this->assertSame(5, $moves->json('meta.total')); // RECEIPT, SALE, SALE, CUSTOMER_RETURN(QUARANTINE), LOSS
        $this->assertCount(2, $moves->json('data'));

        $dest = $this->get_("lots/{$lot}/destinations")->assertOk()->json();
        $this->assertSame(2, $dest['meta']['total']);
        [$walkIn, $b2b] = $dest['data'];
        $this->assertNull($walkIn['customer']);
        $this->assertSame('30.0000', $walkIn['net']);
        $this->assertSame('CUS-CATERING-ABC', $b2b['customer']['code']);
        $this->assertSame(['50.0000', '0.0000', '4.0000', '46.0000'], [$b2b['sold'], $b2b['reversed'], $b2b['returned'], $b2b['net']]);

        $this->assertSame('20.0000', $this->get_("lots/{$lot}")->json('data.remaining.available'));
    }

    public function test_search_filters_and_lot_balances_in_fifo_order(): void
    {
        [, $a] = $this->receiveOn('2026-10-01', '10');
        [$grB, $b] = $this->receiveOn('2026-10-02', '20');
        $this->sell(null, '10', '27000', true, [$this->cash('270000')])->assertCreated(); // depletes A

        $this->assertSame([$b, $a], array_column($this->get_('lots')->json('data'), 'id'), 'newest first');
        $this->assertSame([$b], array_column($this->get_('lots?status=active')->json('data'), 'id'));
        $this->assertSame([$a], array_column($this->get_('lots?status=depleted')->json('data'), 'id'));
        $grNumber = DB::table('goods_receipts')->where('id', $grB)->value('number');
        $this->assertSame([$b], array_column($this->get_("lots?q={$grNumber}")->json('data'), 'id'), 'search by GR number');
        $this->assertSame(2, $this->get_('lots?q=bandung')->json('meta.total'), 'search by supplier name, case-insensitive');
        $this->assertSame(2, $this->get_('lots?q=telur-std')->json('meta.total'), 'search by SKU');
        $this->assertSame(0, $this->get_('lots?q=100%25')->json('meta.total'), 'LIKE wildcards are escaped');
        $this->get_('lots?status=bogus')->assertStatus(422);

        $bal = $this->get_("inventory/lot-balances?product_id={$this->egg}&include_zero=1")->assertOk()->json('data');
        $this->assertSame([$a, $b], array_column($bal, 'lot_id'), 'FIFO order (oldest lot first)');
        $this->assertSame([$b], array_column($this->get_("inventory/lot-balances?product_id={$this->egg}")->json('data'), 'lot_id'));
    }

    public function test_list_queries_do_not_grow_with_page_size(): void
    {
        $this->receiveOn('2026-10-01', '10');
        $count = function (string $uri): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get_($uri)->assertOk();
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'inventory_lots') || str_contains($q['query'], 'stock_lot_balances') || str_contains($q['query'], 'stock_movements')));
            DB::disableQueryLog();

            return $n;
        };
        $one = [$count('lots'), $count('inventory/lot-balances')];
        for ($i = 2; $i <= 12; $i++) {
            $this->receiveOn(sprintf('2026-10-%02d', $i), '10');
        }
        $lot = (string) DB::table('inventory_lots')->orderBy('number')->value('id');
        for ($i = 0; $i < 6; $i++) {
            $this->sell(null, '1', '27000', true, [$this->cash('27000')])->assertCreated();
        }
        $this->assertSame($one, [$count('lots'), $count('inventory/lot-balances')], 'constant query count (no N+1)');
        $this->assertLessThanOrEqual(3, $count("lots/{$lot}/destinations"));
    }

    public function test_trace_follows_a_lot_to_supplier_receipt_sales_customers_and_audit(): void
    {
        [$gr, $lot] = $this->receiveOn('2026-10-03', '150', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]);
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('775000')])->assertCreated()->json('data');
        $number = DB::table('inventory_lots')->where('id', $lot)->value('number');

        foreach ([$number, strtolower($number), $lot] as $ref) {
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('dgway:trace', ['reference' => $ref, '--json' => true]));
            $t = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
            $this->assertSame(['type' => 'inventory_lot', 'id' => $lot], $t['root']);
        }
        $this->assertSame('SUP-BANDUNG', $t['lot']['supplier']['code']);
        $this->assertSame($gr, $t['lot']['goods_receipt']['id']);
        $this->assertSame(['ACCEPT', 'LOSS'], array_column($t['lot']['dispositions'], 'type'));
        $this->assertSame(['RECEIPT', 'RECEIPT', 'LOSS', 'SALE'], array_column($t['stock_movements'], 'movement_type'));
        $this->assertSame([$sale['id']], array_column($t['destinations'], 'sale_id'));
        $this->assertSame(['CUS-CATERING-ABC'], $t['customers']);
        $actions = array_column($t['audit'], 'action');
        foreach (['inventory_lot.created', 'goods_receipt.posted', 'sale.confirmed'] as $a) {
            $this->assertContains($a, $actions);
        }

        // a document trace names the lots its movements touched
        \Illuminate\Support\Facades\Artisan::call('dgway:trace', ['reference' => $sale['number'], '--json' => true]);
        $this->assertSame([$number], array_column(json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['lots'], 'number'));
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('dgway:trace', ['reference' => $number])); // human output renders
    }
}
