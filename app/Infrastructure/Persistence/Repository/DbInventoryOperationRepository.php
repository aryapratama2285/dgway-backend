<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Inventory\InventoryOperation;
use App\Domain\Inventory\InventoryOperationRepository;
use Illuminate\Database\ConnectionInterface;

/** Writes inventory operation documents (append-only table). */
final class DbInventoryOperationRepository implements InventoryOperationRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function add(InventoryOperation $op, ?string $actorUserId): void
    {
        $this->db->table('inventory_operations')->insert([
            'id' => $op->id->value,
            'number' => $op->number->toString(),
            'operation_type' => $op->type->value,
            'node_id' => $op->nodeId->value,
            'product_id' => $op->productId->value,
            'target_product_id' => $op->targetProductId?->value,
            'quantity' => $op->quantity->toStorage(),
            'uom_code' => $op->quantity->uom->code,
            'from_bucket' => $op->fromBucket->value,
            'adjustment_direction' => $op->adjustmentDirection?->value,
            'reason' => $op->reason,
            'business_date' => $op->businessDate->toString(),
            'occurred_at' => $op->occurredAt->format('Y-m-d H:i:s.uP'),
            'actor_user_id' => $actorUserId,
            'reason_code' => $op->reasonCode,
            'reference_text' => $op->referenceText,
            'counted_quantity' => $op->countedQuantity?->toStorage(),
            'system_quantity' => $op->systemQuantity?->toStorage(),
        ]);
    }
}
