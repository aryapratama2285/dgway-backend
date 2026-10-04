<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\ConflictsWithCurrentState;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Quantity\Quantity;

final class InsufficientStock extends DomainException implements ConflictsWithCurrentState
{
    public static function at(StockKey $key, Quantity $available, Quantity $requested, ?string $lotId = null): self
    {
        return new self(
            ($lotId === null ? "" : "Lot {$lotId}: ")."Insufficient {$key->bucket->value} stock: available {$available->toStorage()} {$available->uom->code}, requested {$requested->toStorage()}.",
            'INSUFFICIENT_STOCK',
            [
                'product_id' => $key->productId->value,
                'node_id' => $key->nodeId->value,
                'bucket' => $key->bucket->value,
                'available' => $available->toStorage(),
                'requested' => $requested->toStorage(),
                'uom' => $available->uom->code,
            ] + ($lotId === null ? [] : ['lot_id' => $lotId]),
        );
    }
}
