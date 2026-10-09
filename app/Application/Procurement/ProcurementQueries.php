<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\PurchaseOrder;

interface ProcurementQueries
{
    /** @return Page<PurchaseOrder> filters: status, supplier_id, node_id, date_from, date_to */
    public function purchaseOrders(array $filters, PageRequest $page): Page;

    /** @return Page<GoodsReceipt> filters: status, supplier_id, node_id, purchase_order_id, date_from, date_to */
    public function goodsReceipts(array $filters, PageRequest $page): Page;

    /**
     * F4A: names for presenting these documents (one query per kind, whatever the count).
     *
     * @param  list<PurchaseOrder|GoodsReceipt>  $documents
     */
    public function references(array $documents): ProcurementReferences;

    /** F4A: purchasing-unit conversions of a product, by unit code. @return list<array<string, mixed>> */
    public function uomConversions(string $productId): array;
}
