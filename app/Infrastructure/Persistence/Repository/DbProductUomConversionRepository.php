<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\ProductUomConversion;
use App\Domain\Catalog\ProductUomConversionRepository;
use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Uom;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

final class DbProductUomConversionRepository implements ProductUomConversionRepository
{
    private const TABLE = 'product_uom_conversions';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function findActive(Id $productId, string $unitCode, Uom $baseUnit): ?ProductUomConversion
    {
        $r = $this->db->table(self::TABLE)->where('product_id', $productId->value)->where('uom_code', strtoupper(trim($unitCode)))->where('is_active', true)->first();

        return $r === null ? null : $this->hydrate($r, $baseUnit);
    }

    public function findForUpdate(Id $productId, string $unitCode, Uom $baseUnit): ?ProductUomConversion
    {
        $r = $this->db->table(self::TABLE)->where('product_id', $productId->value)->where('uom_code', strtoupper(trim($unitCode)))->lockForUpdate()->first();

        return $r === null ? null : $this->hydrate($r, $baseUnit);
    }

    public function forProduct(Id $productId, Uom $baseUnit): array
    {
        return array_map(fn (object $r) => $this->hydrate($r, $baseUnit),
            $this->db->table(self::TABLE)->where('product_id', $productId->value)->orderBy('uom_code')->get()->all());
    }

    public function add(ProductUomConversion $c, ?string $actorUserId): void
    {
        try {
            $this->db->table(self::TABLE)->insert([
                'id' => $c->id()->value,
                'product_id' => $c->productId()->value,
                'uom_code' => $c->conversion()->unit->code,
                'factor_to_base' => $c->conversion()->factorToStorage(),
                'is_active' => $c->isActive(),
                'version' => 1,
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
                'created_at' => $this->db->raw('now()'),
                'updated_at' => $this->db->raw('now()'),
            ]);
        } catch (QueryException $e) {
            // two first-time definitions of the same product × unit raced: the loser re-reads and retries
            if ((string) ($e->errorInfo[0] ?? $e->getCode()) === '23505' && str_contains($e->getMessage(), 'product_uom_conversions_product_uom_unique')) {
                throw ConcurrentModification::of('ProductUomConversion', $c->productId()->value.'@'.$c->conversion()->unit->code, 0);
            }
            throw $e;
        }
        $c->syncVersion(1);
    }

    public function save(ProductUomConversion $c, ?string $actorUserId): void
    {
        $expected = $c->version();
        $affected = $this->db->table(self::TABLE)->where('id', $c->id()->value)->where('version', $expected)->update([
            'factor_to_base' => $c->conversion()->factorToStorage(),
            'is_active' => $c->isActive(),
            'version' => $expected + 1,
            'updated_by' => $actorUserId,
            'updated_at' => $this->db->raw('now()'),
        ]);
        if ($affected !== 1) {
            throw ConcurrentModification::of('ProductUomConversion', $c->id()->value, $expected);
        }
        $c->syncVersion($expected + 1);
    }

    public function hasActiveFor(Id $productId): bool
    {
        return $this->db->table(self::TABLE)->where('product_id', $productId->value)->where('is_active', true)->exists();
    }

    private function hydrate(object $r, Uom $baseUnit): ProductUomConversion
    {
        $unit = $this->uoms->find($r->uom_code) ?? throw new RuntimeException("Unknown UOM {$r->uom_code}");

        return ProductUomConversion::reconstitute(Id::fromString($r->id), (int) $r->version, Id::fromString($r->product_id), $unit, $baseUnit,
            (string) $r->factor_to_base, (bool) $r->is_active);
    }
}
