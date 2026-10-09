<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Inventory\InventoryTestCase;

/** F4A helpers on top of the inventory fixtures (supplier, node, TELUR-STD in KG). */
abstract class ProcurementTestCase extends InventoryTestCase
{
    /** @param list<array<string, mixed>>|null $lines */
    protected function createPo(?array $lines = null, ?string $supplier = null): TestResponse
    {
        return $this->cmd('POST', 'purchase-orders', [
            'supplier_id' => $supplier ?? $this->supplier, 'node_id' => $this->node,
            'lines' => $lines ?? [['product_id' => $this->egg, 'quantity' => '100', 'unit_cost' => '23500']],
        ]);
    }

    /** @param list<array<string, mixed>>|null $lines @return array<string, mixed> */
    protected function confirmedPo(?array $lines = null): array
    {
        $po = $this->createPo($lines)->assertCreated()->json('data');

        return $this->cmd('POST', "purchase-orders/{$po['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'CONFIRMED')->json('data');
    }

    /** @param array<string, mixed> $extra */
    protected function poLine(array $po, string $qty, ?string $cost = null, array $extra = [], int $index = 0): array
    {
        return array_filter($extra + [
            'product_id' => $po['lines'][$index]['product_id'],
            'purchase_order_line_id' => $po['lines'][$index]['id'],
            'quantity' => $qty,
            'unit_cost' => $cost,
            'dispositions' => [['type' => 'ACCEPT', 'quantity' => $qty]],
        ], fn ($v) => $v !== null);
    }

    /** Creates + posts a receipt against the PO; returns the posted receipt. @param list<array<string, mixed>> $lines */
    protected function receivePo(array $po, array $lines): array
    {
        $id = $this->createReceipt($lines, $po['id'])->assertCreated()->json('data.id');

        return $this->cmd('POST', "goods-receipts/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED')->json('data');
    }

    protected function po(string $id): array
    {
        return $this->get_("purchase-orders/{$id}")->assertOk()->json('data');
    }
}
