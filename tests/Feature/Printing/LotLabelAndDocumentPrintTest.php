<?php

declare(strict_types=1);

namespace Tests\Feature\Printing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Lots\LotTestCase;

/** A5-C §5–6 — LOT_LABEL, PURCHASE_ORDER and GOODS_RECEIPT print models; received ≠ collection date. */
final class LotLabelAndDocumentPrintTest extends LotTestCase
{
    private function label(string $lot): array
    {
        return $this->get_("lots/{$lot}/label")->assertOk()->json('data');
    }

    /** @return array<string, string> key → label */
    private static function labels(array $doc): array
    {
        return array_column($doc['document']['display_lines'], 'label', 'key');
    }

    public function test_label_of_a_direct_farm_lot_distinguishes_collection_from_received_date(): void
    {
        [$gr, $lot] = $this->receiveOn('2026-10-03', '150', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']], null,
            ['collection_date' => '2026-10-02', 'best_before_date' => '2026-10-24']);
        $l = $this->label($lot);
        $d = $l['document'];
        $this->assertSame([1, 'LOT_LABEL', ['medium' => 'LABEL']], [$l['schema_version'], $l['document_type'], $l['layout']]);
        $this->assertSame('DG WAY', $l['business']['business_name']);
        $number = DB::table('inventory_lots')->where('id', $lot)->value('number');
        $this->assertSame([$lot, $number, 'RECEIPT', 'RECORDED'], [$d['lot_id'], $d['lot_number'], $d['origin'], $d['provenance']]);
        $this->assertSame(['sku' => 'TELUR-STD', 'name' => 'Produk TELUR-STD'], $d['product']);
        $this->assertSame('2026-10-03', $d['received_by_dgway']['business_date']);
        $this->assertSame('2026-10-02', $d['collection_date']);
        $this->assertSame(['value' => '150.0000', 'formatted' => '150', 'uom' => 'KG'], $d['original_quantity']);
        $this->assertSame('SUP-BANDUNG', $d['supplier']['code']);
        $this->assertSame(DB::table('goods_receipts')->where('id', $gr)->value('number'), $d['goods_receipt']['number']);
        $this->assertSame('2026-10-24', $d['best_before_date']);
        $this->assertSame(['payload' => "dgway://lot/{$lot}", 'contract_version' => 1], $d['codes']['qr']);
        $this->assertSame(['symbology' => 'CODE128', 'value' => $number], $d['codes']['barcode']);

        $lines = array_column($d['display_lines'], 'value', 'key');
        $labels = self::labels($l);
        $this->assertSame(['Diterima DG Way', '2026-10-03'], [$labels['received_date'], $lines['received_date']]);
        $this->assertSame(['Tanggal Pengumpulan', '2026-10-02'], [$labels['collection_date'], $lines['collection_date']]);
        $this->assertSame('150 KG', $lines['original_quantity']);
        foreach (['Tanggal Produksi', 'Production', 'Batch', 'Tanggal Batch'] as $wrong) {
            $this->assertStringNotContainsString($wrong, json_encode($d['display_lines']));
        }
        $this->assertArrayNotHasKey('remaining', $d, 'a label carries no mutable quantity');
    }

    public function test_label_of_an_agent_lot_never_claims_a_collection_or_production_date(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '100');
        $d = $this->label($lot)['document'];
        $this->assertNull($d['collection_date']);
        $this->assertNull($d['supplier_reference']);
        $this->assertSame('2026-10-03', $d['received_by_dgway']['business_date']);
        $labels = self::labels($this->label($lot));
        $this->assertArrayNotHasKey('collection_date', $labels, 'unknown collection date is omitted, not replaced by the received date');
        $this->assertArrayNotHasKey('supplier_reference', $labels);
        $this->assertSame('Diterima DG Way', $labels['received_date']);
    }

    public function test_label_of_a_legacy_lot_shows_unrecorded_provenance_and_invents_nothing(): void
    {
        $id = (string) Str::uuid7();
        DB::table('inventory_lots')->insert(['id' => $id, 'number' => 'LOT-260901-001', 'origin' => 'LEGACY', 'product_id' => $this->egg, 'node_id' => $this->node,
            'uom_code' => 'KG', 'source_document_type' => 'legacy_backfill', 'business_date' => '2026-09-01', 'received_at' => '2026-09-01 01:00:00+00']);
        $d = $this->label('LOT-260901-001')['document'];
        $this->assertSame([$id, 'LEGACY', 'UNATTRIBUTED_LEGACY'], [$d['lot_id'], $d['origin'], $d['provenance']]);
        $this->assertSame([null, null, null, null, null], [$d['supplier'], $d['goods_receipt'], $d['collection_date'], $d['original_quantity'], $d['supplier_reference']]);
        $lines = array_column($d['display_lines'], 'value', 'key');
        $this->assertSame('Tidak tercatat (stok sebelum pencatatan lot)', $lines['provenance']);
        $this->assertArrayNotHasKey('supplier', $lines);
        $this->assertArrayNotHasKey('goods_receipt', $lines);
        $this->assertArrayNotHasKey('collection_date', $lines);
        $this->assertSame("dgway://lot/{$id}", $d['codes']['qr']['payload']);
    }

    public function test_label_of_a_regrade_lot_shows_its_parent_and_inherited_facts(): void
    {
        $afkir = $this->product('TELUR-AFKIR');
        [, $lot] = $this->receiveOn('2026-10-03', '100', [['type' => 'ACCEPT', 'quantity' => '85'], ['type' => 'REGRADE', 'quantity' => '15', 'target_product_id' => $afkir]], null,
            ['collection_date' => '2026-10-01']);
        $child = DB::table('inventory_lots')->where('parent_lot_id', $lot)->first();
        $d = $this->label($child->id)['document'];
        $this->assertSame(['REGRADE', 'TELUR-AFKIR', '2026-10-01', 'SUP-BANDUNG'], [$d['origin'], $d['product']['sku'], $d['collection_date'], $d['supplier']['code']]);
        $this->assertSame(['lot_number' => DB::table('inventory_lots')->where('id', $lot)->value('number')], $d['derived_from']);
        $this->assertSame('15.0000', $d['original_quantity']['value']);
        $this->assertSame("dgway://lot/{$child->id}", $d['codes']['qr']['payload']);
    }

    public function test_goods_receipt_and_purchase_order_print_models(): void
    {
        $po = $this->cmd('POST', 'purchase-orders', ['supplier_id' => $this->supplier, 'node_id' => $this->node,
            'lines' => [['product_id' => $this->egg, 'quantity' => '150', 'unit_cost' => '23000']]])->json('data');
        $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk();
        $p = $this->get_("purchase-orders/{$po['id']}/print")->assertOk()->json('data');
        $this->assertSame(['PURCHASE_ORDER', 'CONFIRMED', null], [$p['document_type'], $p['document']['status'], $p['document']['notice']]);
        $this->assertSame(['Rp3.450.000', '150'], [$p['document']['total_amount']['formatted'], $p['document']['lines'][0]['ordered_quantity']['formatted']]);

        $gr = $this->cmd('POST', 'goods-receipts', ['supplier_id' => $this->supplier, 'node_id' => $this->node, 'purchase_order_id' => $po['id'], 'lines' => [
            array_merge($this->line('150', '23000', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10', 'reason' => 'pecah']], null, $po['lines'][0]['id']),
                ['collection_date' => '2026-10-02']),
        ]])->assertCreated()->json('data');
        $draft = $this->get_("goods-receipts/{$gr['id']}/print")->assertOk()->json('data.document');
        $this->assertSame(['DRAFT', null], [$draft['notice']['code'], $draft['lines'][0]['lot']], 'a draft has no lot yet');
        $this->cmd('POST', "goods-receipts/{$gr['id']}/post")->assertOk();
        $g = $this->get_("goods-receipts/{$gr['id']}/print")->assertOk()->json('data');
        $line = $g['document']['lines'][0];
        $this->assertSame(['GOODS_RECEIPT', 'POSTED', $po['number']], [$g['document_type'], $g['document']['status'], $g['document']['purchase_order_number']]);
        $this->assertSame(['ACCEPT' => '140', 'LOSS' => '10'], array_column(array_map(fn ($x) => ['t' => $x['type'], 'q' => $x['quantity']['formatted']], $line['dispositions']), 'q', 't'));
        $this->assertSame('2026-10-02', $line['collection_date']);
        $this->assertSame('2026-10-03', $g['document']['business_date'], 'receipt date stays the receipt date');
        $lot = DB::table('inventory_lots')->where('goods_receipt_id', $gr['id'])->first();
        $this->assertSame([$lot->id, $lot->number, "dgway://lot/{$lot->id}"], [$line['lot']['id'], $line['lot']['number'], $line['lot']['qr_payload']]);
        $this->assertSame('Rp3.450.000', $g['document']['total_cost']['formatted']);
        $this->get_('lots/LOT-999999-999/label')->assertStatus(404);
    }
}
