<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockBucket;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockMovement;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Inventory\LotBalance;
use App\Domain\Inventory\LotBalanceKey;
use App\Domain\Inventory\LotStock;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use RuntimeException;

/**
 * PostgreSQL ledger + projection.
 *
 * Concurrency: lockBalances() first ensures a row exists for every position
 * (INSERT … ON CONFLICT DO NOTHING) and then takes `SELECT … FOR UPDATE` on
 * all of them in key order. Two transactions touching the same position
 * therefore serialise; the second one sees the first one's committed
 * balance, so "both see 5 kg and both take 4 kg" cannot happen. Sorting the
 * keys gives every transaction the same lock order (no deadlock between two
 * multi-position postings).
 */
final class DbStockLedger implements StockLedger
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function lockBalances(array $keys, array $uomByProduct): array
    {
        $this->assertTransaction();
        usort($keys, fn (StockKey $a, StockKey $b) => strcmp($a->toString(), $b->toString()));

        foreach ($keys as $key) {
            $this->db->insert(
                'INSERT INTO stock_balances (product_id, node_id, bucket, uom_code, quantity, updated_at) VALUES (?, ?, ?, ?, 0, now())
                 ON CONFLICT (product_id, node_id, bucket) DO NOTHING',
                [$key->productId->value, $key->nodeId->value, $key->bucket->value, $uomByProduct[$key->productId->value]->code],
            );
        }

        $balances = [];
        foreach ($keys as $key) {
            $row = $this->db->selectOne(
                'SELECT quantity, uom_code FROM stock_balances WHERE product_id = ? AND node_id = ? AND bucket = ? FOR UPDATE',
                [$key->productId->value, $key->nodeId->value, $key->bucket->value],
            );
            $uom = $uomByProduct[$key->productId->value];
            if ($row->uom_code !== $uom->code) {
                throw new RuntimeException("Stock position {$key->toString()} is kept in {$row->uom_code}, not {$uom->code}.");
            }
            $balances[$key->toString()] = StockBalance::of($key, Quantity::of(self::trim((string) $row->quantity), $uom));
        }

        return $balances;
    }

    public function append(StockMovement $m): void
    {
        $this->assertTransaction();
        $this->db->table('stock_movements')->insert([
            'id' => $m->id->value,
            'movement_type' => $m->type->value,
            'product_id' => $m->key->productId->value,
            'node_id' => $m->key->nodeId->value,
            'bucket' => $m->key->bucket->value,
            'direction' => $m->direction->value,
            'quantity' => $m->quantity->toStorage(),
            'uom_code' => $m->quantity->uom->code,
            'unit_cost' => $m->unitCost?->toStorage(),
            'currency' => $m->unitCost?->currency->code,
            'business_date' => $m->businessDate->toString(),
            'occurred_at' => $m->occurredAt->format('Y-m-d H:i:s.uP'),
            'source_document_type' => $m->source->type,
            'source_document_id' => $m->source->id->value,
            'source_line_id' => $m->source->lineId?->value,
            'correlation_id' => $m->correlationId->value,
            'actor_user_id' => $m->actorUserId,
            'reason' => $m->reason,
            'reverses_movement_id' => $m->reversesMovementId?->value,
            'lot_id' => $m->lotId?->value,
        ]);
    }

    public function saveBalance(StockBalance $balance): void
    {
        $this->db->table('stock_balances')
            ->where('product_id', $balance->key->productId->value)
            ->where('node_id', $balance->key->nodeId->value)
            ->where('bucket', $balance->key->bucket->value)
            ->update(['quantity' => $balance->quantity()->toStorage(), 'updated_at' => $this->db->raw('now()')]);
    }

    public function movementsOfSource(string $sourceType, Id $sourceId): array
    {
        $rows = $this->db->table('stock_movements')
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId->value)
            ->orderBy('sequence')
            ->get()->all();

        return array_map(fn (object $r) => $this->hydrate($r), $rows);
    }

    public function isReversed(Id $movementId): bool
    {
        return $this->db->table('stock_movements')->where('reverses_movement_id', $movementId->value)->exists();
    }

    public function hydrate(object $r): StockMovement
    {
        $uom = $this->uoms->find($r->uom_code) ?? throw new RuntimeException("Unknown UOM {$r->uom_code}");

        return StockMovement::reconstitute(
            Id::fromString($r->id),
            MovementType::from($r->movement_type),
            new StockKey(Id::fromString($r->product_id), Id::fromString($r->node_id), StockBucket::from($r->bucket)),
            Direction::from((int) $r->direction),
            Quantity::of(self::trim((string) $r->quantity), $uom),
            BusinessDate::fromString((string) $r->business_date),
            new DateTimeImmutable((string) $r->occurred_at),
            new SourceDocument($r->source_document_type, Id::fromString($r->source_document_id), $r->source_line_id ? Id::fromString($r->source_line_id) : null),
            Id::fromString($r->correlation_id),
            $r->actor_user_id,
            $r->reason,
            $r->unit_cost === null ? null : Money::of((string) $r->unit_cost, Currency::of($r->currency)),
            $r->reverses_movement_id ? Id::fromString($r->reverses_movement_id) : null,
            isset($r->lot_id) ? Id::fromString($r->lot_id) : null,
        );
    }

    public function lockLotBalances(array $keys, array $uomByProduct): array
    {
        $this->assertTransaction();
        usort($keys, fn (LotBalanceKey $a, LotBalanceKey $b) => strcmp($a->toString(), $b->toString()));

        foreach ($keys as $k) {
            $this->db->insert(
                'INSERT INTO stock_lot_balances (lot_id, node_id, bucket, product_id, uom_code, quantity, updated_at) VALUES (?, ?, ?, ?, ?, 0, now())
                 ON CONFLICT (lot_id, node_id, bucket) DO NOTHING',
                [$k->lotId->value, $k->position->nodeId->value, $k->position->bucket->value, $k->position->productId->value, $uomByProduct[$k->position->productId->value]->code],
            );
        }
        $out = [];
        foreach ($keys as $k) {
            $row = $this->db->selectOne('SELECT quantity FROM stock_lot_balances WHERE lot_id = ? AND node_id = ? AND bucket = ? FOR UPDATE',
                [$k->lotId->value, $k->position->nodeId->value, $k->position->bucket->value]);
            $out[$k->toString()] = LotBalance::of($k, Quantity::of(self::trim((string) $row->quantity), $uomByProduct[$k->position->productId->value]));
        }

        return $out;
    }

    public function saveLotBalance(LotBalance $balance): void
    {
        $this->db->table('stock_lot_balances')
            ->where('lot_id', $balance->key->lotId->value)
            ->where('node_id', $balance->key->position->nodeId->value)
            ->where('bucket', $balance->key->position->bucket->value)
            ->update(['quantity' => $balance->quantity()->toStorage(), 'updated_at' => $this->db->raw('now()')]);
    }

    public function lotsInPosition(StockKey $position, Uom $uom): array
    {
        $this->assertTransaction();
        $rows = $this->db->select(<<<'SQL'
            SELECT b.lot_id, b.quantity, l.number, l.business_date, l.received_at
              FROM stock_lot_balances b JOIN inventory_lots l ON l.id = b.lot_id
             WHERE b.product_id = ? AND b.node_id = ? AND b.bucket = ? AND b.quantity > 0
             ORDER BY l.business_date, l.received_at, l.number
               FOR UPDATE OF b
        SQL, [$position->productId->value, $position->nodeId->value, $position->bucket->value]);

        return array_map(fn ($r) => new LotStock(Id::fromString($r->lot_id), $r->number, BusinessDate::fromString((string) $r->business_date),
            new DateTimeImmutable((string) $r->received_at), Quantity::of(self::trim((string) $r->quantity), $uom)), $rows);
    }

    /** "140.0000" → "140", "12.3450" → "12.345": storage scale back to the unit's precision. */
    public static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    private function assertTransaction(): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('Stock postings must run inside a transaction.');
        }
    }
}
