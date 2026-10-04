<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Catalog\CatalogQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Application\Shared\Query\StatusFilter;
use App\Infrastructure\Persistence\Repository\DbProductCategoryRepository;
use App\Infrastructure\Persistence\Repository\DbProductRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class DbCatalogQueries implements CatalogQueries
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly DbProductRepository $products,
    ) {
    }

    public function categories(StatusFilter $status): array
    {
        $q = $this->db->table('product_categories')->orderBy('code');
        self::applyStatus($q, $status);

        return array_map(DbProductCategoryRepository::hydrate(...), $q->get()->all());
    }

    public function products(StatusFilter $status, ?string $search, ?string $categoryId, PageRequest $page): Page
    {
        $q = $this->db->table('products');
        self::applyStatus($q, $status);
        if ($categoryId !== null) {
            $q->where('category_id', $categoryId);
        }
        if ($search !== null && trim($search) !== '') {
            $term = '%'.self::escapeLike(mb_strtolower(trim($search))).'%';
            $q->where(fn (Builder $w) => $w->whereRaw('lower(name) LIKE ?', [$term])->orWhereRaw('lower(sku) LIKE ?', [$term]));
        }

        $total = (clone $q)->count();
        $rows = $q->orderBy('name')->orderBy('sku')->offset($page->offset())->limit($page->perPage)->get()->all();

        return new Page(array_map($this->products->hydrate(...), $rows), $total, $page);
    }

    public function uoms(): array
    {
        return array_map(
            fn ($r) => ['code' => $r->code, 'name' => $r->name, 'precision' => (int) $r->precision, 'is_active' => (bool) $r->is_active],
            $this->db->table('uoms')->orderBy('code')->get()->all(),
        );
    }

    public static function applyStatus(Builder $q, StatusFilter $status, string $column = 'is_active'): void
    {
        match ($status) {
            StatusFilter::ACTIVE => $q->where($column, true),
            StatusFilter::INACTIVE => $q->where($column, false),
            StatusFilter::ALL => null,
        };
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
