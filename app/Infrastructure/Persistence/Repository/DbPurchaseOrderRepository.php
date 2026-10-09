<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\PurchaseOrderCloseReason;
use App\Domain\Procurement\PurchaseOrderLifecycle;
use App\Domain\Procurement\PurchasedIn;
use App\Domain\Shared\Quantity\UnitConversion;
use App\Domain\Shared\Quantity\Uom;
use DateTimeImmutable;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use RuntimeException;

final class DbPurchaseOrderRepository implements PurchaseOrderRepository
{
    private const LABEL = 'Purchase order';

    public function __construct(
        private readonly RepositorySupport $support,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): PurchaseOrder
    {
        return $this->load($id, false);
    }

    public function getForUpdate(Id $id): PurchaseOrder
    {
        return $this->load($id, true);
    }

    public function add(PurchaseOrder $po, ?string $actorUserId = null): void
    {
        $this->support->insert('purchase_orders', [
            'number' => $po->number->toString(),
            'supplier_id' => $po->supplierId->value,
            'node_id' => $po->nodeId->value,
            'business_date' => $po->businessDate->toString(),
            'currency' => $po->currency->code,
            'status' => $po->status()->value,
            'notes' => $po->notes(),
            'cancel_reason' => $po->cancelReason(),
            'created_by' => $actorUserId,
        ], $po, self::LABEL, 'purchase_orders_number_unique', $po->number->toString());

        $this->insertLines($po);
    }

    public function save(PurchaseOrder $po, ?string $actorUserId = null): void
    {
        $l = $po->lifecycle();
        $this->support->update('purchase_orders', [
            'status' => $po->status()->value,
            'notes' => $po->notes(),
            'cancel_reason' => $po->cancelReason(),
            'confirmed_at' => $l->confirmedAt?->format('Y-m-d H:i:s.uP'),
            'confirmed_by' => $l->confirmedBy,
            'cancelled_at' => $l->cancelledAt?->format('Y-m-d H:i:s.uP'),
            'cancelled_by' => $l->cancelledBy,
            'closed_at' => $l->closedAt?->format('Y-m-d H:i:s.uP'),
            'closed_by' => $l->closedBy,
            'close_reason_code' => $l->closeReason?->value,
            'close_note' => $l->closeNote,
        ], $po, self::LABEL);

        foreach ($po->lines() as $l) {
            $this->support->db()->table('purchase_order_lines')->where('id', $l->id->value)
                ->update(['received_quantity' => $l->receivedQuantity()->toStorage(), 'updated_at' => now('UTC')]);
        }
    }

    public function saveDraft(PurchaseOrder $po, bool $replaceLines): void
    {
        $this->support->update('purchase_orders', [
            'supplier_id' => $po->supplierId->value,
            'node_id' => $po->nodeId->value,
            'business_date' => $po->businessDate->toString(),
            'currency' => $po->currency->code,
            'notes' => $po->notes(),
        ], $po, self::LABEL);
        if ($replaceLines) {
            // allowed only while the order is DRAFT (dgway_po_line_guard_f4a)
            $this->support->db()->table('purchase_order_lines')->where('purchase_order_id', $po->id()->value)->delete();
            $this->insertLines($po);
        }
    }

    private function insertLines(PurchaseOrder $po): void
    {
        foreach ($po->lines() as $l) {
            $this->support->db()->table('purchase_order_lines')->insert([
                'id' => $l->id->value,
                'purchase_order_id' => $po->id()->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom_code' => $l->orderedQuantity->uom->code,
                'ordered_quantity' => $l->orderedQuantity->toStorage(),
                'received_quantity' => $l->receivedQuantity()->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'line_total' => $l->lineTotal()->toStorage(),
                'purchase_uom_code' => $l->purchasedIn->quantity->uom->code,
                'purchase_quantity' => $l->purchasedIn->quantity->toStorage(),
                'purchase_unit_price' => $l->purchasedIn->unitPrice->toStorage(),
                'conversion_factor' => $l->purchasedIn->conversion->factorToStorage(),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }
    }

    public function hydrate(object $r, array $lineRows): PurchaseOrder
    {
        $currency = Currency::of($r->currency);
        $lines = array_map(function (object $l) use ($currency): PurchaseOrderLine {
            $uom = $this->uoms->find($l->uom_code) ?? throw new RuntimeException("Unknown UOM {$l->uom_code}");
            $ordered = Quantity::of(DbStockLedger::trim((string) $l->ordered_quantity), $uom);
            $unitCost = Money::of((string) $l->unit_cost, $currency);

            return PurchaseOrderLine::reconstitute(
                Id::fromString($l->id),
                (int) $l->line_no,
                Id::fromString($l->product_id),
                $ordered,
                $unitCost,
                Quantity::of(DbStockLedger::trim((string) $l->received_quantity), $uom),
                $this->purchasedIn($l, $uom, $currency),
            );
        }, $lineRows);

        return PurchaseOrder::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            DocumentNumber::parse($r->number),
            Id::fromString($r->supplier_id),
            Id::fromString($r->node_id),
            BusinessDate::fromString((string) $r->business_date),
            $currency,
            $lines,
            PurchaseOrderStatus::from($r->status),
            $r->notes,
            $r->cancel_reason,
            new PurchaseOrderLifecycle(
                self::at($r->confirmed_at ?? null), $r->confirmed_by ?? null,
                self::at($r->cancelled_at ?? null), $r->cancelled_by ?? null,
                self::at($r->closed_at ?? null), $r->closed_by ?? null,
                isset($r->close_reason_code) ? PurchaseOrderCloseReason::from($r->close_reason_code) : null,
                $r->close_note ?? null,
            ),
        );
    }

    /** The stored purchasing-unit snapshot of a line (F4A columns). */
    private function purchasedIn(object $l, Uom $baseUom, Currency $currency): PurchasedIn
    {
        $unit = $l->purchase_uom_code === $baseUom->code ? $baseUom
            : ($this->uoms->find($l->purchase_uom_code) ?? throw new RuntimeException("Unknown UOM {$l->purchase_uom_code}"));

        return new PurchasedIn(
            UnitConversion::of($unit, $baseUom, (string) $l->conversion_factor),
            Quantity::of(DbStockLedger::trim((string) $l->purchase_quantity), $unit),
            Money::of((string) $l->purchase_unit_price, $currency),
        );
    }

    private static function at(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }

    private function load(Id $id, bool $lock): PurchaseOrder
    {
        $row = $this->support->fetch('purchase_orders', $id->value, self::LABEL, $lock);
        $lines = $this->support->db()->table('purchase_order_lines')->where('purchase_order_id', $id->value)->orderBy('line_no')->get()->all();

        return $this->hydrate($row, $lines);
    }
}
