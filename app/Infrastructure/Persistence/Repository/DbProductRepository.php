<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;
use RuntimeException;

final class DbProductRepository implements ProductRepository
{
    private const TABLE = 'products';
    private const LABEL = 'Product';

    public function __construct(
        private readonly RepositorySupport $support,
        private readonly UnitOfMeasureRepository $uoms,
    ) {
    }

    public function get(Id $id): Product
    {
        return $this->hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL));
    }

    public function getForUpdate(Id $id): Product
    {
        return $this->hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL, lock: true));
    }

    public function add(Product $product): void
    {
        $this->support->insert(self::TABLE, self::row($product), $product, self::LABEL, 'products_sku_unique', $product->sku()->value);
    }

    public function save(Product $product): void
    {
        $this->support->update(self::TABLE, self::row($product), $product, self::LABEL);
    }

    public function countActiveInCategory(Id $categoryId): int
    {
        return $this->support->db()->table(self::TABLE)->where('category_id', $categoryId->value)->where('is_active', true)->count();
    }

    public function hydrate(object $r): Product
    {
        // Historical rows may reference a now-inactive unit: load it regardless of status.
        $uom = $this->uoms->find($r->base_uom_code) ?? throw new RuntimeException("Unknown UOM {$r->base_uom_code}");

        return Product::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            BusinessCode::of($r->sku),
            Name::of($r->name),
            Id::fromString($r->category_id),
            $uom,
            $r->description,
            (bool) $r->is_active,
        );
    }

    /** @return array<string, mixed> */
    private static function row(Product $p): array
    {
        return [
            'sku' => $p->sku()->value,
            'name' => $p->name()->value,
            'category_id' => $p->categoryId()->value,
            'base_uom_code' => $p->baseUom()->code,
            'description' => $p->description(),
            'is_active' => $p->isActive(),
        ];
    }
}
