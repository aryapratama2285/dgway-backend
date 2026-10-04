<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Inventory\InventoryLot;
use App\Domain\Inventory\LotOrigin;
use App\Domain\Inventory\LotRepository;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\EntityNotFound;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** inventory_lots is append-only: lots are inserted once and never updated. */
final class DbLotRepository implements LotRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): InventoryLot
    {
        $r = $this->db->table('inventory_lots')->where('id', $id->value)->first() ?? throw EntityNotFound::of('Lot', $id->value);
        $uom = $this->uoms->find($r->uom_code) ?? throw new RuntimeException("Unknown UOM {$r->uom_code}");
        $date = fn ($v) => $v === null ? null : BusinessDate::fromString((string) $v);

        return InventoryLot::reconstitute(
            Id::fromString($r->id), DocumentNumber::parse($r->number), LotOrigin::from($r->origin), Id::fromString($r->product_id), Id::fromString($r->node_id),
            $r->original_quantity === null ? null : Quantity::of(DbStockLedger::trim((string) $r->original_quantity), $uom),
            $r->parent_lot_id ? Id::fromString($r->parent_lot_id) : null, $r->supplier_id ? Id::fromString($r->supplier_id) : null,
            $r->goods_receipt_id ? Id::fromString($r->goods_receipt_id) : null, $r->goods_receipt_line_id ? Id::fromString($r->goods_receipt_line_id) : null,
            new SourceDocument($r->source_document_type, Id::fromString($r->source_document_id ?? $r->id)),
            BusinessDate::fromString((string) $r->business_date), new DateTimeImmutable((string) $r->received_at), $date($r->manufactured_date), $date($r->best_before_date),
            $date($r->collection_date ?? null), $r->supplier_reference ?? null,
        );
    }

    public function add(InventoryLot $lot, ?string $actorUserId = null): void
    {
        $this->db->table('inventory_lots')->insert([
            'id' => $lot->id()->value,
            'number' => $lot->number->toString(),
            'origin' => $lot->origin->value,
            'product_id' => $lot->productId->value,
            'node_id' => $lot->nodeId->value,
            'uom_code' => $lot->originalQuantity?->uom->code,
            'original_quantity' => $lot->originalQuantity?->toStorage(),
            'parent_lot_id' => $lot->parentLotId?->value,
            'supplier_id' => $lot->supplierId?->value,
            'goods_receipt_id' => $lot->goodsReceiptId?->value,
            'goods_receipt_line_id' => $lot->goodsReceiptLineId?->value,
            'source_document_type' => $lot->source->type,
            'source_document_id' => $lot->source->id->value,
            'business_date' => $lot->businessDate->toString(),
            'received_at' => $lot->receivedAt->format('Y-m-d H:i:s.uP'),
            'manufactured_date' => $lot->manufacturedDate?->toString(),
            'best_before_date' => $lot->bestBeforeDate?->toString(),
            'collection_date' => $lot->collectionDate?->toString(),
            'supplier_reference' => $lot->supplierReference,
            'created_by' => $actorUserId,
        ]);
    }

    public function legacyLotOf(Id $productId, Id $nodeId): ?Id
    {
        $id = $this->db->table('inventory_lots')->where('origin', 'LEGACY')->where('product_id', $productId->value)->where('node_id', $nodeId->value)->value('id');

        return $id === null ? null : Id::fromString($id);
    }
}
