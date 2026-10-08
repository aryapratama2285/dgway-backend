<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Uom;

/** Persistence port for reorder points (one per product × node). */
interface ReorderPointRepository
{
    /** Locked (FOR UPDATE) threshold of this product × node, or null when none was ever set. */
    public function findForUpdate(Id $productId, Id $nodeId, Uom $baseUom): ?ReorderPoint;

    public function add(ReorderPoint $point, ?string $actorUserId): void;

    public function save(ReorderPoint $point, ?string $actorUserId): void;

    /** Does the product have any ACTIVE (non-null) threshold? */
    public function hasActiveFor(Id $productId): bool;
}
