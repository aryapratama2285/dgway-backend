<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Support\Facades\DB;

/**
 * F4A — purchasing units: per-product conversion master, snapshot on PO and
 * receipt lines, exact base quantities, base unit cost = price ÷ factor
 * (half-up, 4 decimals), history unchanged when the master changes.
 *
 * Uses reference units that exist in every database: a packaging item stocked
 * in PCS and bought per IKAT (bundle).
 */
final class PurchasingUnitTest extends ProcurementTestCase
{
    private string $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->box = $this->product('KARDUS-S', null, 'PCS');
    }

    private function setConversion(string $factor, bool $active = true): array
    {
        return $this->cmd('PUT', "products/{$this->box}/uom-conversions/IKAT", ['factor_to_base' => $factor, 'is_active' => $active])->assertOk()->json('data');
    }

    public function test_conversion_master_rules(): void
    {
        $c = $this->setConversion('25');
        $this->assertSame(['IKAT', 'PCS', '25.000000', true, 1], [$c['uom'], $c['base_uom'], $c['factor_to_base'], $c['is_active'], $c['version']]);
        $this->cmd('PUT', "products/{$this->box}/uom-conversions/PCS", ['factor_to_base' => '1'])->assertStatus(422)->assertJsonPath('error.code', 'UOM_CONVERSION_INVALID');
        $this->cmd('PUT', "products/{$this->box}/uom-conversions/IKAT", ['factor_to_base' => '0.0000001'])->assertStatus(422)->assertJsonPath('error.code', 'UOM_CONVERSION_INVALID');
        $this->cmd('PUT', "products/{$this->box}/uom-conversions/NOPE", ['factor_to_base' => '2'])->assertStatus(422)->assertJsonPath('error.code', 'UOM_UNKNOWN');
        $this->cmd('PUT', "products/{$this->box}/uom-conversions/IKAT", ['factor_to_base' => '30', 'expected_version' => 9])->assertStatus(409);
        $this->get_("products/{$this->box}/uom-conversions")->assertOk()->assertJsonPath('data.0.factor_to_base', '25.000000');
        // the base unit is locked while a conversion is active (app + trigger)
        $this->cmd('PATCH', "products/{$this->box}", ['base_uom' => 'IKAT'])->assertStatus(422);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'catalog.uom_conversion_set')->count());
    }

    public function test_po_and_receipt_in_a_purchasing_unit_keep_a_snapshot(): void
    {
        $this->setConversion('30');
        // Rp50,000 per IKAT of 30 PCS → 1,666.6667 per PCS (half-up), total exact in IKAT
        $po = $this->confirmedPo([['product_id' => $this->box, 'quantity' => '4', 'uom' => 'IKAT', 'unit_cost' => '50000']]);
        $line = $po['lines'][0];
        $this->assertSame(['PCS', '120.0000', '1666.6667', '200000.0000', 'IKAT', '4.0000', '50000.0000', '30.000000'],
            [$line['uom'], $line['ordered_quantity'], $line['unit_cost'], $line['line_total'], $line['purchase_uom'], $line['purchase_quantity'], $line['purchase_unit_price'], $line['conversion_factor']]);

        // the master changes afterwards: the PO keeps its own conversion
        $this->setConversion('25');
        $gr = $this->receivePo($po, [$this->poLine($po, '3', null, ['uom' => 'IKAT', 'dispositions' => [['type' => 'ACCEPT', 'quantity' => '2'], ['type' => 'QUARANTINE', 'quantity' => '1']]])]);
        $g = $gr['lines'][0];
        $this->assertSame(['90.0000', 'IKAT', '3.0000', '30.000000', '50000.0000', '1666.6667', '150000.0000'],
            [$g['received_quantity'], $g['purchase_uom'], $g['purchase_quantity'], $g['conversion_factor'], $g['purchase_unit_cost'], $g['unit_cost'], $g['line_cost']]);
        $this->assertSame(['60.0000', '30.0000'], [$this->stock($this->box)['available'], $this->stock($this->box)['quarantine']]);
        $this->assertSame('1666.6667', (string) DB::table('stock_movements')->where('movement_type', 'RECEIPT')->value('unit_cost'));

        // remaining 1 IKAT = 30 PCS; receiving in the base unit defaults the cost to the PO base unit cost
        $po = $this->po($po['id']);
        $this->assertSame('30.0000', $po['lines'][0]['open_quantity']);
        $this->createReceipt([$this->poLine($po, '31')], $po['id'])->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');
        $gr2 = $this->receivePo($po, [$this->poLine($po, '30')]);
        $this->assertSame(['PCS', '1.000000', '1666.6667', '50000.0010'],
            [$gr2['lines'][0]['purchase_uom'], $gr2['lines'][0]['conversion_factor'], $gr2['lines'][0]['unit_cost'], $gr2['lines'][0]['line_cost']]);
        $this->assertSame('RECEIVED', $this->po($po['id'])['status']);
        $this->assertLedgerConsistent();
    }

    public function test_inexact_or_missing_conversion_is_refused_never_rounded(): void
    {
        $this->createPo([['product_id' => $this->box, 'quantity' => '1', 'uom' => 'IKAT', 'unit_cost' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'UOM_CONVERSION_MISSING');
        $this->setConversion('2.5');
        $this->createPo([['product_id' => $this->box, 'quantity' => '1', 'uom' => 'IKAT', 'unit_cost' => '1']])->assertStatus(422)
            ->assertJsonPath('error.code', 'UOM_CONVERSION_INVALID'); // 2.5 PCS is not a whole piece
        $this->createPo([['product_id' => $this->box, 'quantity' => '2', 'uom' => 'IKAT', 'unit_cost' => '1']])->assertCreated();
        $this->setConversion('2.5', false);
        $this->createPo([['product_id' => $this->box, 'quantity' => '2', 'uom' => 'IKAT', 'unit_cost' => '1']])->assertStatus(422)->assertJsonPath('error.code', 'UOM_CONVERSION_MISSING');
    }
}
