<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Inventory\InventoryOperation;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;

/** Stable JSON contracts for procurement and inventory documents. */
final class ProcurementResources
{
    /** @return array<string, mixed> */
    public static function purchaseOrder(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id()->value,
            'number' => $po->number->toString(),
            'status' => $po->status()->value,
            'supplier_id' => $po->supplierId->value,
            'node_id' => $po->nodeId->value,
            'business_date' => $po->businessDate->toString(),
            'currency' => $po->currency->code,
            'total_amount' => $po->totalAmount()->toStorage(),
            'notes' => $po->notes(),
            'cancel_reason' => $po->cancelReason(),
            'lines' => array_map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom' => $l->orderedQuantity->uom->code,
                'ordered_quantity' => $l->orderedQuantity->toStorage(),
                'received_quantity' => $l->receivedQuantity()->toStorage(),
                'open_quantity' => $l->openQuantity()->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'line_total' => $l->lineTotal()->toStorage(),
            ], $po->lines()),
            'version' => $po->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function goodsReceipt(GoodsReceipt $gr): array
    {
        $yield = $gr->yieldSummary();

        return [
            'id' => $gr->id()->value,
            'number' => $gr->number->toString(),
            'status' => $gr->status()->value,
            'type' => $gr->purchaseOrderId === null ? 'DIRECT_PURCHASE' : 'PURCHASE_ORDER',
            'supplier_id' => $gr->supplierId->value,
            'node_id' => $gr->nodeId->value,
            'purchase_order_id' => $gr->purchaseOrderId?->value,
            'business_date' => $gr->businessDate->toString(),
            'received_at' => $gr->receivedAt->format(DATE_ATOM),
            'posted_at' => $gr->postedAt()?->format(DATE_ATOM),
            'reversed_at' => $gr->reversedAt()?->format(DATE_ATOM),
            'status_reason' => $gr->statusReason(),
            'currency' => $gr->currency->code,
            'total_cost' => $gr->totalCost()->toStorage(),
            'notes' => $gr->notes(),
            'lines' => array_map(fn (GoodsReceiptLine $l) => [
                'id' => $l->id->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'purchase_order_line_id' => $l->purchaseOrderLineId?->value,
                'uom' => $l->receivedQuantity->uom->code,
                'received_quantity' => $l->receivedQuantity->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'line_cost' => $l->lineCost()->toStorage(),
                'dispositions' => array_map(fn ($d) => [
                    'type' => $d->type->value,
                    'quantity' => $d->quantity->toStorage(),
                    'target_product_id' => $d->targetProductId?->value,
                    'reason' => $d->reason,
                ], $l->dispositions),
                // optional upstream facts (A5-C): null = not known / not stated by the supplier
                'manufactured_date' => $l->manufacturedDate?->toString(),
                'best_before_date' => $l->bestBeforeDate?->toString(),
                'collection_date' => $l->collectionDate?->toString(),
                'supplier_reference' => $l->supplierReference,
            ], $gr->lines()),
            // Raw figures for HPP / supplier-quality analysis; not an accounting policy.
            'yield_analysis' => $yield,
            'version' => $gr->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function inventoryOperation(InventoryOperation $op): array
    {
        return [
            'id' => $op->id->value,
            'number' => $op->number->toString(),
            'type' => $op->type->value,
            'node_id' => $op->nodeId->value,
            'product_id' => $op->productId->value,
            'target_product_id' => $op->targetProductId?->value,
            'quantity' => $op->quantity->toStorage(),
            'uom' => $op->quantity->uom->code,
            'from_bucket' => $op->fromBucket->value,
            'direction' => $op->adjustmentDirection?->name,
            'reason' => $op->reason,
            'reason_code' => $op->reasonCode,
            'reference_text' => $op->referenceText,
            'counted_quantity' => $op->countedQuantity?->toStorage(),
            'system_quantity' => $op->systemQuantity?->toStorage(),
            'business_date' => $op->businessDate->toString(),
            'occurred_at' => $op->occurredAt->format(DATE_ATOM),
        ];
    }
}
