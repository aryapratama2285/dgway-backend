<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\DB;

final class DirectPurchaseTest extends InventoryTestCase
{
    public function test_direct_purchase_150kg_with_10kg_loss(): void
    {
        $res = $this->createReceipt([$this->line('150', '23000', [
            ['type' => 'ACCEPT', 'quantity' => '140'],
            ['type' => 'LOSS', 'quantity' => '10', 'reason' => 'pecah di perjalanan'],
        ])])->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.type', 'DIRECT_PURCHASE')
            ->assertJsonPath('data.lines.0.line_cost', '3450000.0000')
            ->assertJsonPath('data.total_cost', '3450000.0000');
        $this->assertMatchesRegularExpression('/^GR-261003-\d{3}$/', $res->json('data.number'));
        $id = $res->json('data.id');

        // nothing in stock until the receipt is posted
        $this->assertSame(0, DB::table('stock_movements')->count());

        $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED');

        $s = $this->stock();
        $this->assertSame('140.0000', $s['available']);
        $this->assertSame('0.0000', $s['quarantine']);
        $this->assertSame('140.0000', $s['ledger_on_hand']);

        $types = DB::table('stock_movements')->orderBy('sequence')->pluck('movement_type')->all();
        $this->assertSame(['RECEIPT', 'RECEIPT', 'LOSS'], $types);
        $this->assertSame('150', (string) (float) DB::table('stock_movements')->where('movement_type', 'RECEIPT')->sum('quantity'));
        $this->assertSame('10', (string) (float) DB::table('stock_movements')->where('movement_type', 'LOSS')->sum('quantity'));
        $this->assertSame(1, DB::table('stock_movements')->distinct()->count('correlation_id'));
        $this->assertLedgerConsistent();
    }
}
