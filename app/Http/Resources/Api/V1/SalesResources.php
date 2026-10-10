<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Sales\CustomerReturn;
use App\Domain\Sales\CustomerReturnLine;
use App\Domain\Sales\Payment;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleLine;
use App\Domain\Shared\Time\BusinessDate;

/** Stable JSON contracts for sales, payments and customer returns. */
final class SalesResources
{
    /**
     * @param  array<string, mixed>|null  $customer  label (code, name, phone) or null for walk-in
     * @param  list<Payment>|null  $payments
     * @param  list<CustomerReturn>|null  $returns
     * @param  array<string, array{product: ?array<string, mixed>, lots: list<array<string, string>>}>|null  $lineDetails  F4B, by sale line id (sale detail only)
     * @return array<string, mixed>
     */
    public static function sale(Sale $s, ?array $customer, BusinessDate $today, ?array $payments = null, ?array $returns = null, ?array $lineDetails = null): array
    {
        $out = [
            'id' => $s->id()->value,
            'number' => $s->number->toString(),
            'origin' => $s->origin->value,
            'order_reference_id' => $s->orderReferenceId?->value,
            'status' => $s->status()->value,
            'payment_status' => $s->paymentStatus(),
            'customer' => $customer,
            'is_walk_in' => $s->customerId === null,
            'node_id' => $s->nodeId->value,
            'business_date' => $s->businessDate->toString(),
            'currency' => $s->currency->code,
            'subtotal' => $s->subtotal()->toStorage(),
            'discount_total' => $s->discountTotal()->toStorage(),
            'grand_total' => $s->grandTotal()->toStorage(),
            'paid' => $s->paid()->toStorage(),
            'credited' => $s->credited()->toStorage(),
            'outstanding' => $s->outstanding()->toStorage(),
            'payment_term_days' => $s->termDays(),
            'due_date' => $s->dueDate()?->toString(),
            'is_overdue' => $s->isOverdue($today),
            'confirmed_at' => $s->confirmedAt()?->format(DATE_ATOM),
            'completed_at' => $s->completedAt()?->format(DATE_ATOM),
            'reversed_at' => $s->reversedAt()?->format(DATE_ATOM),
            'status_reason' => $s->statusReason(),
            'notes' => $s->notes(),
            'lines' => array_map(fn (SaleLine $l) => [
                'id' => $l->id->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom' => $l->quantity->uom->code,
                'quantity' => $l->quantity->toStorage(),
                'unit_price' => $l->unitPrice->toStorage(),
                'subtotal' => $l->subtotal()->toStorage(),
                'discount' => $l->discount->toStorage(),
                'total' => $l->total()->toStorage(),
                'returned_quantity' => $l->returnedQuantity()->toStorage(),
                'returned_amount' => $l->returnedAmount()->toStorage(),
                'returnable_quantity' => $l->returnableQuantity()->toStorage(),
            ] + ($lineDetails === null ? [] : [
                'product' => $lineDetails[$l->id->value]['product'] ?? null,
                // lots consumed FIFO by this line (allocation order) and how much of each came back
                'lots' => $lineDetails[$l->id->value]['lots'] ?? [],
            ]), $s->lines()),
            'version' => $s->version(),
        ];
        if ($payments !== null) {
            $out['payments'] = array_map(self::payment(...), $payments);
        }
        if ($returns !== null) {
            $out['returns'] = array_map(self::customerReturn(...), $returns);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function payment(Payment $p): array
    {
        return [
            'id' => $p->id()->value,
            'number' => $p->number->toString(),
            'status' => $p->status(),
            'sale_id' => $p->saleId->value,
            'customer_id' => $p->customerId?->value,
            'method' => $p->method->value,
            'amount' => $p->amount->toStorage(),
            'currency' => $p->amount->currency->code,
            'paid_at' => $p->paidAt->format(DATE_ATOM),
            'business_date' => $p->businessDate->toString(),
            'reference' => $p->reference,
            'notes' => $p->notes,
            'actor_user_id' => $p->actorUserId,
            'reversal' => $p->reversal() === null ? null : [
                'reason' => $p->reversal()->reason,
                'reversed_at' => $p->reversal()->reversedAt->format(DATE_ATOM),
                'business_date' => $p->reversal()->businessDate->toString(),
                'actor_user_id' => $p->reversal()->actorUserId,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function customerReturn(CustomerReturn $c): array
    {
        return [
            'id' => $c->id()->value,
            'number' => $c->number->toString(),
            'sale_id' => $c->saleId->value,
            'customer_id' => $c->customerId?->value,
            'node_id' => $c->nodeId->value,
            'business_date' => $c->businessDate->toString(),
            'returned_at' => $c->returnedAt->format(DATE_ATOM),
            'total_value' => $c->totalValue()->toStorage(),
            'credit_amount' => $c->creditAmount->toStorage(),
            'refund_amount' => $c->refundAmount->toStorage(),
            'refund_method' => $c->refundMethod?->value,
            'reason' => $c->reason,
            'lines' => array_map(fn (CustomerReturnLine $l) => [
                'id' => $l->id->value,
                'sale_line_id' => $l->saleLineId->value,
                'product_id' => $l->productId->value,
                'uom' => $l->quantity->uom->code,
                'quantity' => $l->quantity->toStorage(),
                'value' => $l->value->toStorage(),
                'disposition' => $l->disposition->value,
                'reason' => $l->reason,
            ], $c->lines),
        ];
    }
}
