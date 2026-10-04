<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Procurement\PurchaseOrderStatus;
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
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }
    }

    public function save(PurchaseOrder $po): void
    {
        $this->support->update('purchase_orders', [
            'status' => $po->status()->value,
            'notes' => $po->notes(),
            'cancel_reason' => $po->cancelReason(),
        ], $po, self::LABEL);

        foreach ($po->lines() as $l) {
            $this->support->db()->table('purchase_order_lines')->where('id', $l->id->value)
                ->update(['received_quantity' => $l->receivedQuantity()->toStorage(), 'updated_at' => now('UTC')]);
        }
    }

    public function hydrate(object $r, array $lineRows): PurchaseOrder
    {
        $currency = Currency::of($r->currency);
        $lines = array_map(function (object $l) use ($currency): PurchaseOrderLine {
            $uom = $this->uoms->find($l->uom_code) ?? throw new RuntimeException("Unknown UOM {$l->uom_code}");

            return PurchaseOrderLine::reconstitute(
                Id::fromString($l->id),
                (int) $l->line_no,
                Id::fromString($l->product_id),
                Quantity::of(DbStockLedger::trim((string) $l->ordered_quantity), $uom),
                Money::of((string) $l->unit_cost, $currency),
                Quantity::of(DbStockLedger::trim((string) $l->received_quantity), $uom),
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
        );
    }

    private function load(Id $id, bool $lock): PurchaseOrder
    {
        $row = $this->support->fetch('purchase_orders', $id->value, self::LABEL, $lock);
        $lines = $this->support->db()->table('purchase_order_lines')->where('purchase_order_id', $id->value)->orderBy('line_no')->get()->all();

        return $this->hydrate($row, $lines);
    }
}
