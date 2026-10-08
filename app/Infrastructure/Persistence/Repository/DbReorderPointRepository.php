<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Inventory\ReorderPoint;
use App\Domain\Inventory\ReorderPointRepository;
use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

final class DbReorderPointRepository implements ReorderPointRepository
{
    private const TABLE = 'reorder_points';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function findForUpdate(Id $productId, Id $nodeId, Uom $baseUom): ?ReorderPoint
    {
        $r = $this->db->table(self::TABLE)->where('product_id', $productId->value)->where('node_id', $nodeId->value)->lockForUpdate()->first();

        return $r === null ? null : ReorderPoint::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            Id::fromString($r->product_id),
            Id::fromString($r->node_id),
            $baseUom,
            $r->min_quantity === null ? null : Quantity::of(DbStockLedger::trim((string) $r->min_quantity), $baseUom),
        );
    }

    public function add(ReorderPoint $point, ?string $actorUserId): void
    {
        try {
            $this->insert($point, $actorUserId);
        } catch (QueryException $e) {
            // two first-time "set" commands for the same product × node raced: the loser retries with a fresh read
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23505' && str_contains($e->getMessage(), 'reorder_points_product_node_unique')) {
                throw ConcurrentModification::of('ReorderPoint', $point->productId()->value.'@'.$point->nodeId()->value, 0);
            }
            throw $e;
        }
        $point->syncVersion(1);
    }

    private function insert(ReorderPoint $point, ?string $actorUserId): void
    {
        $this->db->table(self::TABLE)->insert([
            'id' => $point->id()->value,
            'product_id' => $point->productId()->value,
            'node_id' => $point->nodeId()->value,
            'min_quantity' => $point->minQuantity()?->toStorage(),
            'version' => 1,
            'updated_by' => $actorUserId,
            'created_at' => $this->db->raw('now()'),
            'updated_at' => $this->db->raw('now()'),
        ]);
    }

    public function save(ReorderPoint $point, ?string $actorUserId): void
    {
        $expected = $point->version();
        $affected = $this->db->table(self::TABLE)->where('id', $point->id()->value)->where('version', $expected)->update([
            'min_quantity' => $point->minQuantity()?->toStorage(),
            'version' => $expected + 1,
            'updated_by' => $actorUserId,
            'updated_at' => $this->db->raw('now()'),
        ]);
        if ($affected !== 1) {
            throw ConcurrentModification::of('ReorderPoint', $point->id()->value, $expected);
        }
        $point->syncVersion($expected + 1);
    }

    public function hasActiveFor(Id $productId): bool
    {
        return $this->db->table(self::TABLE)->where('product_id', $productId->value)->whereNotNull('min_quantity')->exists();
    }
}
