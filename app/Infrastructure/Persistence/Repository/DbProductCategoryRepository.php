<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Catalog\ProductCategory;
use App\Domain\Catalog\ProductCategoryRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class DbProductCategoryRepository implements ProductCategoryRepository
{
    private const TABLE = 'product_categories';
    private const LABEL = 'Product category';

    public function __construct(private readonly RepositorySupport $support)
    {
    }

    public function get(Id $id): ProductCategory
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL));
    }

    public function getForUpdate(Id $id): ProductCategory
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL, lock: true));
    }

    public function add(ProductCategory $category): void
    {
        $this->support->insert(self::TABLE, self::row($category), $category, self::LABEL, 'product_categories_code_unique', $category->code()->value);
    }

    public function save(ProductCategory $category): void
    {
        $this->support->update(self::TABLE, self::row($category), $category, self::LABEL);
    }

    public function ancestorIds(Id $id): array
    {
        $rows = $this->support->db()->select(
            <<<'SQL'
                WITH RECURSIVE chain(id, parent_id, depth) AS (
                    SELECT id, parent_id, 1 FROM product_categories WHERE id = ?
                    UNION ALL
                    SELECT c.id, c.parent_id, chain.depth + 1
                      FROM product_categories c JOIN chain ON c.id = chain.parent_id
                     WHERE chain.depth < 20
                )
                SELECT id FROM chain ORDER BY depth
                SQL,
            [$id->value],
        );

        return array_map(fn ($r) => Id::fromString($r->id), $rows);
    }

    public function countActiveChildren(Id $id): int
    {
        return $this->support->db()->table(self::TABLE)->where('parent_id', $id->value)->where('is_active', true)->count();
    }

    public function subtreeHeight(Id $id): int
    {
        $row = $this->support->db()->selectOne(
            <<<'SQL'
                WITH RECURSIVE sub(id, depth) AS (
                    SELECT id, 1 FROM product_categories WHERE id = ?
                    UNION ALL
                    SELECT c.id, sub.depth + 1 FROM product_categories c JOIN sub ON c.parent_id = sub.id
                     WHERE sub.depth < 20
                )
                SELECT max(depth) AS height FROM sub
                SQL,
            [$id->value],
        );

        return (int) ($row->height ?? 1);
    }

    public static function hydrate(object $r): ProductCategory
    {
        return ProductCategory::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            BusinessCode::of($r->code),
            Name::of($r->name),
            $r->parent_id === null ? null : Id::fromString($r->parent_id),
            (bool) $r->is_active,
        );
    }

    /** @return array<string, mixed> */
    private static function row(ProductCategory $c): array
    {
        return [
            'code' => $c->code()->value,
            'name' => $c->name()->value,
            'parent_id' => $c->parentId()?->value,
            'is_active' => $c->isActive(),
        ];
    }
}
