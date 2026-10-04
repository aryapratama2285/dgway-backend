<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\MasterData\MasterDataTestCase;

/**
 * Shared fixtures for procurement / inventory feature tests: an active
 * supplier, a receiving+inventory node and egg products stocked in KG.
 */
abstract class InventoryTestCase extends MasterDataTestCase
{
    protected string $supplier;

    protected string $node;

    protected string $egg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = $this->supplier();
        $this->node = $this->node();
        $this->egg = $this->product('TELUR-STD');
    }

    protected function supplier(string $code = 'SUP-BANDUNG'): string
    {
        return $this->cmd('POST', 'business-partners', [
            'code' => $code, 'name' => "Supplier {$code}", 'party_type' => 'ORGANIZATION', 'supplier' => ['term_days' => 0],
        ])->assertCreated()->json('data.id');
    }

    /** @param list<string> $caps */
    protected function node(string $code = 'DG-WAY-PRIMARY', array $caps = ['RECEIVING', 'INVENTORY', 'FULFILLMENT']): string
    {
        return $this->cmd('POST', 'nodes', ['code' => $code, 'name' => $code, 'capabilities' => $caps])->assertCreated()->json('data.id');
    }

    protected function product(string $sku = 'TLR-A', ?string $categoryId = null, string $uom = 'KG'): string
    {
        $categoryId ??= DB::table('product_categories')->where('code', 'EGG')->value('id') ?? $this->category();

        return parent::product($sku, $categoryId, $uom);
    }

    /** @param list<array<string, mixed>> $dispositions */
    protected function line(string $qty, ?string $cost = '23000', array $dispositions = [], ?string $product = null, ?string $poLine = null): array
    {
        return array_filter([
            'product_id' => $product ?? $this->egg,
            'quantity' => $qty,
            'unit_cost' => $cost,
            'purchase_order_line_id' => $poLine,
            'dispositions' => $dispositions ?: [['type' => 'ACCEPT', 'quantity' => $qty]],
        ], fn ($v) => $v !== null);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function createReceipt(array $lines, ?string $poId = null, ?string $key = null): TestResponse
    {
        return $this->cmd('POST', 'goods-receipts', array_filter([
            'supplier_id' => $this->supplier, 'node_id' => $this->node, 'purchase_order_id' => $poId, 'lines' => $lines,
        ], fn ($v) => $v !== null), $key);
    }

    /** Creates and posts a receipt; returns its id. */
    protected function receive(array $lines, ?string $poId = null): string
    {
        $id = $this->createReceipt($lines, $poId)->assertCreated()->json('data.id');
        $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED');

        return $id;
    }

    protected function stock(?string $product = null, ?string $node = null): array
    {
        return $this->get_('inventory/current?product_id='.($product ?? $this->egg).'&node_id='.($node ?? $this->node))->assertOk()->json('data');
    }

    protected function assertLedgerConsistent(): void
    {
        $this->get_('inventory/integrity')->assertOk()->assertJsonPath('data.consistent', true);
    }
}
