<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Partner\PartnerQueries;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Application\Shared\Query\StatusFilter;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PartnerRole;
use App\Infrastructure\Persistence\Repository\DbBusinessPartnerRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class DbPartnerQueries implements PartnerQueries
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function partners(StatusFilter $status, ?PartnerRole $role, ?CustomerType $customerType, ?string $search, PageRequest $page): Page
    {
        $q = $this->db->table('business_partners as p')->select('p.*');
        DbCatalogQueries::applyStatus($q, $status, 'p.is_active');

        if ($role !== null || $customerType !== null) {
            $q->whereExists(function (Builder $r) use ($role, $customerType, $status): void {
                $r->selectRaw('1')->from('business_partner_roles as r')->whereColumn('r.partner_id', 'p.id');
                if ($role !== null) {
                    $r->where('r.role', $role->value);
                }
                if ($customerType !== null) {
                    $r->where('r.role', PartnerRole::CUSTOMER->value)->where('r.customer_type', $customerType->value);
                }
                DbCatalogQueries::applyStatus($r, $status, 'r.is_active');
            });
        }

        if ($search !== null && trim($search) !== '') {
            $raw = trim($search);
            $term = '%'.DbCatalogQueries::escapeLike(mb_strtolower($raw)).'%';
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            $q->where(function (Builder $w) use ($term, $digits): void {
                $w->whereRaw('lower(p.name) LIKE ?', [$term])->orWhereRaw('lower(p.code) LIKE ?', [$term]);
                if (strlen($digits) >= 6) {
                    // match phone regardless of 08… / +628… formatting
                    $w->orWhere('p.phone', 'like', '%'.substr(ltrim($digits, '0'), -10).'%');
                }
            });
        }

        $total = (clone $q)->count();
        $rows = $q->orderBy('p.name')->orderBy('p.code')->offset($page->offset())->limit($page->perPage)->get()->all();

        $roles = $rows === [] ? collect() : $this->db->table('business_partner_roles')
            ->whereIn('partner_id', array_map(fn ($r) => $r->id, $rows))->get()->groupBy('partner_id');

        $items = array_map(fn ($r) => DbBusinessPartnerRepository::hydrate($r, $roles->get($r->id)?->all() ?? []), $rows);

        return new Page($items, $total, $page);
    }
}
