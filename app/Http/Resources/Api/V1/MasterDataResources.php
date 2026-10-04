<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductCategory;
use App\Domain\Network\Location;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;
use App\Domain\Partner\BusinessPartner;

/**
 * Public JSON shapes of master data. These are API contracts, deliberately
 * independent of table columns (e.g. address is nested, roles are objects).
 */
final class MasterDataResources
{
    /** @return array<string, mixed> */
    public static function category(ProductCategory $c): array
    {
        return [
            'id' => $c->id()->value,
            'code' => $c->code()->value,
            'name' => $c->name()->value,
            'parent_id' => $c->parentId()?->value,
            'is_active' => $c->isActive(),
            'version' => $c->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function product(Product $p): array
    {
        return [
            'id' => $p->id()->value,
            'sku' => $p->sku()->value,
            'name' => $p->name()->value,
            'category_id' => $p->categoryId()->value,
            'base_uom' => ['code' => $p->baseUom()->code, 'precision' => $p->baseUom()->precision],
            'description' => $p->description(),
            'is_active' => $p->isActive(),
            'version' => $p->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function partner(BusinessPartner $p): array
    {
        $s = $p->supplierRole();
        $c = $p->customerRole();

        return [
            'id' => $p->id()->value,
            'code' => $p->code()->value,
            'name' => $p->name()->value,
            'legal_name' => $p->legalName()?->value,
            'party_type' => $p->partyType()->value,
            'phone' => $p->phone()?->e164,
            'phone_local' => $p->phone()?->local(),
            'email' => $p->email()?->value,
            'address' => $p->address()?->jsonSerialize(),
            'notes' => $p->notes(),
            'is_active' => $p->isActive(),
            'roles' => array_values(array_filter([$s ? 'SUPPLIER' : null, $c ? 'CUSTOMER' : null])),
            'supplier' => $s === null ? null : [
                'is_active' => $s->isActive(),
                'usable' => $p->isActiveSupplier(),
                'term_days' => $s->terms()->termDays,
                'notes' => $s->notes(),
            ],
            'customer' => $c === null ? null : [
                'is_active' => $c->isActive(),
                'usable' => $p->isActiveCustomer(),
                'customer_type' => $c->type()->value,
                'term_days' => $c->terms()->termDays,
                'credit_limit' => $c->terms()->creditLimit?->toStorage(),
                'is_cash' => $c->terms()->isCash(),
                'notes' => $c->notes(),
            ],
            'version' => $p->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function location(Location $l): array
    {
        return [
            'id' => $l->id()->value,
            'code' => $l->code()->value,
            'name' => $l->name()->value,
            'address' => $l->address()?->jsonSerialize(),
            'notes' => $l->notes(),
            'is_active' => $l->isActive(),
            'version' => $l->version(),
        ];
    }

    /** @return array<string, mixed> */
    public static function node(Node $n): array
    {
        return [
            'id' => $n->id()->value,
            'code' => $n->code()->value,
            'name' => $n->name()->value,
            'location_id' => $n->locationId()?->value,
            'capabilities' => array_map(fn (NodeCapability $c) => $c->value, $n->capabilities()),
            'notes' => $n->notes(),
            'is_active' => $n->isActive(),
            'version' => $n->version(),
        ];
    }
}
