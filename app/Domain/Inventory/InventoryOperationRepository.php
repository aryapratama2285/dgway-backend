<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** Persistence port for inventory operation documents (append-only). */
interface InventoryOperationRepository
{
    public function add(InventoryOperation $operation, ?string $actorUserId): void;
}
