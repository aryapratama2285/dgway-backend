<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Procurement\Disposition;
use App\Domain\Procurement\DispositionType;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\GoodsReceiptStatus;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Support\Str;
use RuntimeException;

final class DbGoodsReceiptRepository implements GoodsReceiptRepository
{
    private const LABEL = 'Goods receipt';

    public function __construct(
        private readonly RepositorySupport $support,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): GoodsReceipt
    {
        return $this->load($id, false);
    }

    public function getForUpdate(Id $id): GoodsReceipt
    {
        return $this->load($id, true);
    }

    public function add(GoodsReceipt $gr, ?string $actorUserId = null): void
    {
        $this->support->insert('goods_receipts', $this->header($gr) + [
            'number' => $gr->number->toString(),
            'supplier_id' => $gr->supplierId->value,
            'node_id' => $gr->nodeId->value,
            'purchase_order_id' => $gr->purchaseOrderId?->value,
            'business_date' => $gr->businessDate->toString(),
            'received_at' => $gr->receivedAt->format('Y-m-d H:i:sP'),
            'currency' => $gr->currency->code,
            'created_by' => $actorUserId,
        ], $gr, self::LABEL, 'goods_receipts_number_unique', $gr->number->toString());

        $db = $this->support->db();
        foreach ($gr->lines() as $l) {
            $db->table('goods_receipt_lines')->insert([
                'id' => $l->id->value,
                'goods_receipt_id' => $gr->id()->value,
                'line_no' => $l->lineNo,
                'product_id' => $l->productId->value,
                'uom_code' => $l->receivedQuantity->uom->code,
                'received_quantity' => $l->receivedQuantity->toStorage(),
                'unit_cost' => $l->unitCost->toStorage(),
                'line_cost' => $l->lineCost()->toStorage(),
                'purchase_order_line_id' => $l->purchaseOrderLineId?->value,
                'manufactured_date' => $l->manufacturedDate?->toString(),
                'best_before_date' => $l->bestBeforeDate?->toString(),
                'collection_date' => $l->collectionDate?->toString(),
                'supplier_reference' => $l->supplierReference,
            ]);
            foreach ($l->dispositions as $d) {
                $db->table('goods_receipt_dispositions')->insert([
                    'id' => (string) Str::uuid7(),
                    'goods_receipt_line_id' => $l->id->value,
                    'disposition_type' => $d->type->value,
                    'quantity' => $d->quantity->toStorage(),
                    'target_product_id' => $d->targetProductId?->value,
                    'reason' => $d->reason,
                ]);
            }
        }
    }

    public function save(GoodsReceipt $gr, ?string $actorUserId = null): void
    {
        $actor = match ($gr->status()) {
            GoodsReceiptStatus::POSTED => ['posted_by' => $actorUserId],
            GoodsReceiptStatus::REVERSED => ['reversed_by' => $actorUserId],
            default => [],
        };
        $this->support->update('goods_receipts', $this->header($gr) + $actor, $gr, self::LABEL);
    }

    /** @param list<object> $lineRows @param array<string, list<object>> $dispositionsByLine */
    public function hydrate(object $r, array $lineRows, array $dispositionsByLine): GoodsReceipt
    {
        $currency = Currency::of($r->currency);
        $lines = array_map(function (object $l) use ($currency, $dispositionsByLine): GoodsReceiptLine {
            $uom = $this->uoms->find($l->uom_code) ?? throw new RuntimeException("Unknown UOM {$l->uom_code}");
            $dispositions = array_map(fn (object $d) => Disposition::of(
                DispositionType::from($d->disposition_type),
                Quantity::of(DbStockLedger::trim((string) $d->quantity), $uom),
                $d->target_product_id ? Id::fromString($d->target_product_id) : null,
                $d->reason,
            ), $dispositionsByLine[$l->id] ?? []);

            return GoodsReceiptLine::reconstitute(
                Id::fromString($l->id), (int) $l->line_no, Id::fromString($l->product_id),
                Quantity::of(DbStockLedger::trim((string) $l->received_quantity), $uom),
                Money::of((string) $l->unit_cost, $currency),
                $dispositions,
                $l->purchase_order_line_id ? Id::fromString($l->purchase_order_line_id) : null,
                isset($l->manufactured_date) ? BusinessDate::fromString((string) $l->manufactured_date) : null,
                isset($l->best_before_date) ? BusinessDate::fromString((string) $l->best_before_date) : null,
                isset($l->collection_date) ? BusinessDate::fromString((string) $l->collection_date) : null,
                $l->supplier_reference ?? null,
            );
        }, $lineRows);

        return GoodsReceipt::reconstitute(
            Id::fromString($r->id), (int) $r->version, DocumentNumber::parse($r->number),
            Id::fromString($r->supplier_id), Id::fromString($r->node_id),
            $r->purchase_order_id ? Id::fromString($r->purchase_order_id) : null,
            BusinessDate::fromString((string) $r->business_date),
            new DateTimeImmutable((string) $r->received_at),
            $currency, $lines, GoodsReceiptStatus::from($r->status), $r->notes,
            $r->posted_at ? new DateTimeImmutable((string) $r->posted_at) : null,
            $r->reversed_at ? new DateTimeImmutable((string) $r->reversed_at) : null,
            $r->status_reason,
        );
    }

    /** @return array{0: list<object>, 1: array<string, list<object>>} */
    public function detailRows(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [[], []];
        }
        $db = $this->support->db();
        $lines = $db->table('goods_receipt_lines')->whereIn('goods_receipt_id', $receiptIds)->orderBy('line_no')->get()->all();
        $dispositions = $lines === [] ? [] : $db->table('goods_receipt_dispositions')
            ->whereIn('goods_receipt_line_id', array_map(fn ($l) => $l->id, $lines))->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('goods_receipt_line_id')->map(fn ($g) => $g->all())->all();

        return [$lines, $dispositions];
    }

    /** @return array<string, mixed> */
    private function header(GoodsReceipt $gr): array
    {
        return [
            'status' => $gr->status()->value,
            'notes' => $gr->notes(),
            'posted_at' => $gr->postedAt()?->format('Y-m-d H:i:s.uP'),
            'reversed_at' => $gr->reversedAt()?->format('Y-m-d H:i:s.uP'),
            'status_reason' => $gr->statusReason(),
        ];
    }

    private function load(Id $id, bool $lock): GoodsReceipt
    {
        $row = $this->support->fetch('goods_receipts', $id->value, self::LABEL, $lock);
        [$lines, $dispositions] = $this->detailRows([$id->value]);

        return $this->hydrate($row, $lines, $dispositions);
    }
}
