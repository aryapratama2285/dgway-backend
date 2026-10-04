<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Identity\Id;

interface LotRepository
{
    public function get(Id $id): InventoryLot;

    public function add(InventoryLot $lot, ?string $actorUserId = null): void;

    /** The LEGACY lot standing for pre-lot stock of this product at this node, if any. */
    public function legacyLotOf(Id $productId, Id $nodeId): ?Id;
}
