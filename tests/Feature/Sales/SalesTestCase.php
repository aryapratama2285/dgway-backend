<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Inventory\InventoryTestCase;

/**
 * Fixtures for A4: supplier/node/product from InventoryTestCase, plus
 * customers and a helper to put stock on hand through a real receipt.
 */
abstract class SalesTestCase extends InventoryTestCase
{
    protected function customer(string $code = 'CUS-CATERING-ABC', int $termDays = 7, ?string $creditLimit = null, string $type = 'CATERING'): string
    {
        return $this->cmd('POST', 'business-partners', [
            'code' => $code, 'name' => "Customer {$code}", 'party_type' => 'ORGANIZATION', 'phone' => '0812'.random_int(1000000, 9999999),
            'customer' => array_filter(['customer_type' => $type, 'term_days' => $termDays, 'credit_limit' => $creditLimit], fn ($v) => $v !== null),
        ])->assertCreated()->json('data.id');
    }

    /** Puts $qty KG of TELUR-STD (or $product) on hand via a posted direct purchase. */
    protected function stockUp(string $qty = '140', ?string $product = null): void
    {
        $this->receive([$this->line($qty, '23000', [], $product)]);
    }

    /** @param list<array<string, mixed>> $payments */
    protected function sell(?string $customer, string $qty, string $price, bool $confirm = true, array $payments = [], ?string $key = null, array $extra = []): TestResponse
    {
        return $this->cmd('POST', 'sales', array_filter([
            'customer_id' => $customer,
            'node_id' => $this->node,
            'lines' => [array_filter(['product_id' => $extra['product'] ?? $this->egg, 'quantity' => $qty, 'unit_price' => $price, 'discount' => $extra['discount'] ?? null], fn ($v) => $v !== null)],
            'confirm' => $confirm,
            'payments' => $payments ?: null,
        ], fn ($v) => $v !== null), $key);
    }

    protected function cash(string $amount, string $method = 'CASH'): array
    {
        return ['method' => $method, 'amount' => $amount];
    }

    protected function pay(string $saleId, string $amount, string $method = 'CASH', ?string $key = null): TestResponse
    {
        return $this->cmd('POST', "sales/{$saleId}/payments", ['method' => $method, 'amount' => $amount], $key);
    }
}
