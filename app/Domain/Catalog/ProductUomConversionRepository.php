<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Uom;

/** Persistence port for purchasing-unit conversions (one per product × unit). */
interface ProductUomConversionRepository
{
    /** Active conversion of the product into [$unitCode], or null. */
    public function findActive(Id $productId, string $unitCode, Uom $baseUnit): ?ProductUomConversion;

    /** Locked (FOR UPDATE) conversion, active or not, or null when none was ever defined. */
    public function findForUpdate(Id $productId, string $unitCode, Uom $baseUnit): ?ProductUomConversion;

    /** @return list<ProductUomConversion> every conversion of the product, by unit code */
    public function forProduct(Id $productId, Uom $baseUnit): array;

    public function add(ProductUomConversion $conversion, ?string $actorUserId): void;

    public function save(ProductUomConversion $conversion, ?string $actorUserId): void;

    public function hasActiveFor(Id $productId): bool;
}
