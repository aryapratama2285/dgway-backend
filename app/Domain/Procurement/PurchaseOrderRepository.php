<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Identity\Id;

interface PurchaseOrderRepository
{
    public function get(Id $id): PurchaseOrder;

    public function getForUpdate(Id $id): PurchaseOrder;

    public function add(PurchaseOrder $order, ?string $actorUserId = null): void;

    /** Saves status, received quantities and notes (lines are fixed after draft). */
    public function save(PurchaseOrder $order): void;
}
