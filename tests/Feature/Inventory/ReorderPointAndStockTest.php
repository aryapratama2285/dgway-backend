<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * F3 — inventory list read model, reorder points (per product × node,
 * base UOM, AVAILABLE <= min, null disables), dashboard low-stock count,
 * base-UOM lock and the non-destructive rollback of the F3 migration.
 */
final class ReorderPointAndStockTest extends InventoryTestCase
{
    private string $tape;

    private string $label;

    protected function setUp(): void
    {
        parent::setUp();
        $cat = $this->category('SUPPLY');
        $this->tape = $this->cmd('POST', 'products', ['sku' => 'SELOTIP', 'name' => 'Selotip bening', 'category_id' => $cat, 'base_uom' => 'PCS', 'classification' => 'CONSUMABLE'])
            ->assertCreated()->json('data.id');
        $this->label = $this->cmd('POST', 'products', ['sku' => 'LABEL-THERMAL', 'name' => 'Label thermal 50x30', 'category_id' => $cat, 'base_uom' => 'PCS', 'classification' => 'PACKAGING'])
            ->assertCreated()->json('data.id');
    }

    private function setPoint(string $product, ?string $min, ?int $expectedVersion = null, ?string $key = null): TestResponse
    {
        return $this->cmd('POST', 'inventory/reorder-points', array_filter(
            ['product_id' => $product, 'node_id' => $this->node, 'min_quantity' => $min, 'expected_version' => $expectedVersion],
            fn ($v, $k) => $k === 'min_quantity' || $v !== null,
            ARRAY_FILTER_USE_BOTH,
        ), $key);
    }

    public function test_stock_list_is_a_read_model_with_classification_search_and_filters(): void
    {
        $this->receive([$this->line('75', '25000')]);
        $this->receive([$this->line('20', '5000', [], $this->tape)]);

        $rows = $this->get_('inventory/stock')->assertOk()->json('data');
        $this->assertSame(['Produk TELUR-STD', 'Selotip bening'], array_column(array_column($rows, 'product'), 'name'), 'only positions holding stock, by name');
        $egg = $rows[0];
        $this->assertSame(['SELLABLE', 'KG', 3, '75.0000', '75.0000', null, false],
            [$egg['product']['classification'], $egg['uom']['code'], $egg['uom']['precision'], $egg['available'], $egg['on_hand'], $egg['reorder_point'], $egg['is_low_stock']]);
        $this->assertSame('DG-WAY-PRIMARY', $egg['node']['code']);

        $this->get_('inventory/stock?classification=CONSUMABLE')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'SELOTIP');
        $this->get_('inventory/stock?q=telur')->assertOk()->assertJsonPath('meta.total', 1);
        $this->get_('inventory/stock?q=label')->assertOk()->assertJsonPath('meta.total', 0);
        $zero = collect($this->get_('inventory/stock?include_zero=1&classification=PACKAGING')->assertOk()->json('data'));
        $this->assertSame(['LABEL-THERMAL'], $zero->pluck('product.sku')->all(), 'include_zero lists products without stock (consumption picker)');
        $this->assertSame('0.0000', $zero[0]['available']);
        $this->get_("inventory/stock?product_id={$this->label}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->get_('inventory/stock?classification=ASSET')->assertStatus(422);

        // no write path: a PUT on the read model does not exist
        $this->cmd('PUT', 'inventory/stock', ['available' => '999'])->assertStatus(405);
    }

    public function test_reorder_point_set_update_clear_with_version_and_audit(): void
    {
        $p = $this->setPoint($this->tape, '10')->assertOk()
            ->assertJsonPath('data.min_quantity', '10.0000')->assertJsonPath('data.uom', 'PCS')->assertJsonPath('data.version', 1)->json('data');
        $this->setPoint($this->tape, '12', 1)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.min_quantity', '12.0000');
        $this->setPoint($this->tape, '15', 1)->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        $this->setPoint($this->tape, '12', 2)->assertOk()->assertJsonPath('data.version', 2, 'no change, no new version');
        $this->setPoint($this->tape, null)->assertOk()->assertJsonPath('data.min_quantity', null)->assertJsonPath('data.version', 3);

        $this->assertSame(1, DB::table('reorder_points')->count(), 'one row per product × node, kept when cleared');
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'inventory.reorder_point_set')->where('entity_id', $p['id'])->count());
        $this->get_("inventory/reorder-points?product_id={$this->tape}")->assertOk()->assertJsonPath('data.0.min_quantity', null)
            ->assertJsonPath('data.0.uom', 'PCS')->assertJsonPath('data.0.updated_by', $this->owner->id);

        $this->setPoint($this->tape, '1.5')->assertStatus(422)->assertJsonPath('error.code', 'QUANTITY_PRECISION_EXCEEDED');
        $this->setPoint($this->tape, '-1')->assertStatus(422);
        $this->cmd('POST', 'inventory/reorder-points', ['product_id' => $this->tape, 'node_id' => $this->node])->assertStatus(422); // min_quantity must be present (null clears)
        $office = $this->node('KANTOR', ['FULFILLMENT']);
        $this->cmd('POST', 'inventory/reorder-points', ['product_id' => $this->tape, 'node_id' => $office, 'min_quantity' => '5'])
            ->assertStatus(422)->assertJsonPath('error.code', 'NODE_NOT_USABLE');

        $key = (string) Str::uuid();
        $this->setPoint($this->label, '100', null, $key)->assertOk();
        $this->setPoint($this->label, '100', null, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'inventory.reorder_point_set')->where('entity_id', DB::table('reorder_points')->where('product_id', $this->label)->value('id'))->count());
    }

    public function test_low_stock_uses_available_at_the_node_with_less_or_equal_and_null_disables(): void
    {
        $this->receive([$this->line('20', '5000', [], $this->tape)]);
        $this->setPoint($this->tape, '10')->assertOk();
        $this->setPoint($this->label, '50')->assertOk(); // no stock at all → low

        $this->assertLowStock(['LABEL-THERMAL']);
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $this->node, 'product_id' => $this->tape, 'quantity' => '10', 'reason_code' => 'DELIVERY'])->assertCreated();
        $this->assertLowStock(['LABEL-THERMAL', 'SELOTIP'], 'available 10 <= min 10 is low (boundary included)');
        $row = collect($this->get_('inventory/stock')->json('data'))->firstWhere('product.sku', 'SELOTIP');
        $this->assertSame(['10.0000', '10.0000', true], [$row['available'], $row['reorder_point'], $row['is_low_stock']]);

        $this->setPoint($this->label, null)->assertOk();
        $this->assertLowStock(['SELOTIP'], 'null disables monitoring');
        $this->assertNull(collect($this->get_('inventory/stock?include_zero=1')->json('data'))->firstWhere('product.sku', 'LABEL-THERMAL')['reorder_point']);

        $this->cmd('POST', 'products/'.$this->tape.'/deactivate')->assertOk();
        $this->assertLowStock([], 'inactive products are not monitored');
    }

    /** @param list<string> $skus */
    private function assertLowStock(array $skus, string $why = ''): void
    {
        $rows = $this->get_('inventory/stock?low_stock=1')->assertOk()->json('data');
        $this->assertSame($skus, array_column(array_column($rows, 'product'), 'sku'), $why);
        $this->get_('dashboard')->assertOk()->assertJsonPath('data.inventory.low_stock_now', count($skus), 'dashboard counts the same positions');
    }

    public function test_dashboard_low_stock_now_counts_positions_not_products(): void
    {
        $second = $this->node('GUDANG-2', ['INVENTORY']);
        $this->setPoint($this->tape, '5')->assertOk();
        $this->cmd('POST', 'inventory/reorder-points', ['product_id' => $this->tape, 'node_id' => $second, 'min_quantity' => '5'])->assertOk();
        $this->get_('dashboard')->assertOk()->assertJsonPath('data.inventory.low_stock_now', 2, 'one product, two positions');
        $this->get_('inventory/stock?low_stock=1')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_base_uom_cannot_change_while_an_active_reorder_point_exists(): void
    {
        // the tape has never been transacted, so its base UOM may change …
        $this->setPoint($this->tape, '10')->assertOk();
        // … but not while a threshold expressed in PCS is active
        $this->cmd('PATCH', "products/{$this->tape}", ['base_uom' => 'KG'])->assertStatus(422)->assertJsonPath('error.code', 'BASE_UOM_LOCKED_BY_REORDER_POINT');
        $this->assertSame('PCS', DB::table('products')->where('id', $this->tape)->value('base_uom_code'));

        // the database refuses it too (last line of defence)
        try {
            DB::table('products')->where('id', $this->tape)->update(['base_uom_code' => 'KG']);
            $this->fail('the trigger accepted a base UOM change under an active reorder point');
        } catch (QueryException $e) {
            $this->assertStringContainsString('BASE_UOM_LOCKED_BY_REORDER_POINT', $e->getMessage());
        }

        // a threshold does NOT count as a transaction: classification can still change
        $this->cmd('PATCH', "products/{$this->tape}", ['classification' => 'PACKAGING'])->assertOk()->assertJsonPath('data.classification', 'PACKAGING');

        // clearing the threshold unlocks the unit
        $this->setPoint($this->tape, null)->assertOk();
        $this->cmd('PATCH', "products/{$this->tape}", ['base_uom' => 'KG'])->assertOk()->assertJsonPath('data.base_uom.code', 'KG');
        $this->get_("inventory/reorder-points?product_id={$this->tape}")->assertOk()->assertJsonPath('data.0.uom', 'KG', 'unit derived from the product, never stored');
    }

    public function test_reorder_point_rows_are_never_deleted_and_keep_their_identity(): void
    {
        $id = $this->setPoint($this->tape, '10')->assertOk()->json('data.id');
        foreach ([
            fn () => DB::table('reorder_points')->where('id', $id)->delete(),
            fn () => DB::table('reorder_points')->where('id', $id)->update(['product_id' => $this->label]),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('identity guard missing');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/never deleted|is immutable/', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::table('reorder_points')->where('id', $id)->where('product_id', $this->tape)->count());
    }

    public function test_f3_migration_rollback_refuses_to_destroy_history_or_thresholds(): void
    {
        $migration = require base_path('database/migrations/2026_10_06_100100_add_internal_consumption_and_reorder_points.php');

        // thresholds alone are enough to refuse
        $this->setPoint($this->tape, '10')->assertOk();
        $this->assertRollbackRefused($migration, '1 reorder point');

        // consumption history too
        $this->receive([$this->line('20', '5000', [], $this->tape)]);
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $this->node, 'product_id' => $this->tape, 'quantity' => '1', 'reason_code' => 'ADMIN'])->assertCreated();
        $this->assertRollbackRefused($migration, '1 INTERNAL_CONSUMPTION movement');
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('reorder_points'), 'nothing was dropped');
        $this->assertSame(1, DB::table('inventory_operations')->where('operation_type', 'INTERNAL_CONSUMPTION')->count());
    }

    private function assertRollbackRefused(object $migration, string $mentions): void
    {
        try {
            $migration->down();
            $this->fail('rollback ran although F3 data exists');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }
}
