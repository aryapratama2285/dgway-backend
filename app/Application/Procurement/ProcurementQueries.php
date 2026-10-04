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
}
