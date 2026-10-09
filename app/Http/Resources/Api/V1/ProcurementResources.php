<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Inventory\InventoryOperation;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Application\Procurement\ProcurementReferences;

/** Stable JSON contracts for procurement and inventory documents. */
final class ProcurementResources
{
    /**
     * F4A additions (all additive): supplier/node/product names (when [$refs]
     * are given), purchasing-unit snapshot per line, lifecycle timestamps, close
     * reason, unfulfilled quantity (CLOSED only) and receiving progress.
     *
     * @return array<string, mixed>
     */
    public static function purchaseOrder(PurchaseOrder $po, ?ProcurementReferences $refs = null): array
    {
        $refs ??= ProcurementReferences::none();
        $l = $po->lifecycle();
        $closed = $po->status() === PurchaseOrderStatus::CLOSED;
        $lines = $po->lines();

        return [
            'id' => $po->id()->value,
            'number' => $po->number->toString(),
            'status' => $po->status()->value,
            'supplier_id' => $po->supplierId->value,
            'supplier' => $refs->partners[$po->supplierId->value] ?? null,
            'node_id' => $po->nodeId->value,
            'node' => $refs->nodes[$po->nodeId->value] ?? null,
            'business_date' => $po->businessDate->toString(),
            'currency' => $po->currency->code,
            'total_amount' => $po->totalAmount()->toStorage(),
            'notes' => $po->notes(),
            'cancel_reason' => $po->cancelReason(),
            'close_reason_code' => $l->closeReason?->value,
            'close_note' => $l->closeNote,
            'confirmed_at' => $l->confirmedAt?->format(DATE_ATOM),
            'cancelled_at' => $l->cancelledAt?->format(DATE_ATOM),
            'closed_at' => $l->closedAt?->format(DATE_ATOM),
            // quantities of different units are never summed: progress counts lines
            'receiving' => [
                'lines_total' => count($lines),
                'lines_fully_received' => count(array_filter($lines, fn (PurchaseOrderLine $x) => $x->isFullyReceived())),
                'lines_with_receipts' => count(array_filter($lines, fn (PurchaseOrderLine $x) => $x->receivedQuantity()->isPositive())),
                'receipts' => $refs->receiptsByOrder[$po->id()->value]['count'] ?? null,
                'posted_receipts' => $refs->receiptsByOrder[$po->id()->value]['posted'] ?? null,
            ],
            'lines' => array_map(fn (PurchaseOrderLine $x) => [
                'id' => $x->id->value,
                'line_no' => $x->lineNo,
                'product_id' => $x->productId->value,
                'product' => $refs->products[$x->productId->value] ?? null,
                'uom' => $x->orderedQuantity->uom->code,
                'ordered_quantity' => $x->orderedQuantity->toStorage(),
                'received_quantity' => $x->receivedQuantity()->toStorage(),
                'open_quantity' => $x->openQuantity()->toStorage(),
                // CLOSED: what will never arrive (history; ordered is never rewritten)
                'unfulfilled_quantity' => $closed ? $x->unfulfilledQuantity()->toStorage() : null,
                'unit_cost' => $x->unitCost->toStorage(),
                'line_total' => $x->lineTotal()->toStorage(),
                'purchase_uom' => $x->purchasedIn->quantity->uom->code,
                'purchase_quantity' => $x->purchasedIn->quantity->toStorage(),
                'purchase_unit_price' => $x->purchasedIn->unitPrice->toStorage(),
                'conversion_factor' => $x->purchasedIn->conversion->factorToStorage(),
            ], $lines),
            'version' => $po->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function goodsReceipt(GoodsReceipt $gr, ?ProcurementReferences $refs = null): array
    {
        $refs ??= ProcurementReferences::none();
        $yield = $gr->yieldSummary();

        return [
            'id' => $gr->id()->value,
            'number' => $gr->number->toString(),
            'status' => $gr->status()->value,
            'type' => $gr->purchaseOrderId === null ? 'DIRECT_PURCHASE' : 'PURCHASE_ORDER',
            'supplier_id' => $gr->supplierId->value,
            'supplier' => $refs->partners[$gr->supplierId->value] ?? null,
            'node_id' => $gr->nodeId->value,
            'node' => $refs->nodes[$gr->nodeId->value] ?? null,
            'purchase_order_id' => $gr->purchaseOrderId?->value,
            'purchase_order' => $gr->purchaseOrderId === null ? null : ($refs->purchaseOrders[$gr->purchaseOrderId->value] ?? null),
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
                'product' => $refs->products[$l->productId->value] ?? null,
                'purchase_order_line_id' => $l->purchaseOrderLineId?->value,
                'uom' => $l->receivedQuantity->uom->code,
                'received_quantity' => $l->receivedQuantity->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'line_cost' => $l->lineCost()->toStorage(),
                'purchase_uom' => $l->purchasedIn->quantity->uom->code,
                'purchase_quantity' => $l->purchasedIn->quantity->toStorage(),
                'purchase_unit_cost' => $l->purchasedIn->unitPrice->toStorage(),
                'conversion_factor' => $l->purchasedIn->conversion->factorToStorage(),
                'lot' => $refs->lotsByReceiptLine[$l->id->value] ?? null,
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
