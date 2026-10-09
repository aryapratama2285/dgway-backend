<?php

declare(strict_types=1);

namespace App\Application\Procurement;

/**
 * F4A: display names for the ids a procurement document refers to (supplier,
 * node, products, purchase order, lot per receipt line). Read-only; documents
 * keep ids only, so the names are looked up when presenting.
 */
final class ProcurementReferences
{
    /**
     * @param  array<string, array{id: string, code: string, name: string}>  $partners
     * @param  array<string, array{id: string, code: string, name: string}>  $nodes
     * @param  array<string, array{id: string, sku: string, name: string, classification: string, base_uom: string}>  $products
     * @param  array<string, array{id: string, number: string, status: string}>  $purchaseOrders
     * @param  array<string, array{id: string, number: string}>  $lotsByReceiptLine
     * @param  array<string, array{count: int, posted: int}>  $receiptsByOrder
     */
    public function __construct(
        public readonly array $partners = [],
        public readonly array $nodes = [],
        public readonly array $products = [],
        public readonly array $purchaseOrders = [],
        public readonly array $lotsByReceiptLine = [],
        public readonly array $receiptsByOrder = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
