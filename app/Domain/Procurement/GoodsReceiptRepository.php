<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Identity\Id;

interface GoodsReceiptRepository
{
    public function get(Id $id): GoodsReceipt;

    /** Row-locks the receipt: concurrent post/reverse of the same receipt serialise here. */
    public function getForUpdate(Id $id): GoodsReceipt;

    public function add(GoodsReceipt $receipt, ?string $actorUserId = null): void;

    /**
     * Saves the status transition only; lines and dispositions are immutable
     * after draft. The actor is recorded as poster / reverser accordingly.
     */
    public function save(GoodsReceipt $receipt, ?string $actorUserId = null): void;

    /** F4A: saves an edited DRAFT — date, received_at, notes and, when [$replaceLines], all lines and dispositions. */
    public function saveDraft(GoodsReceipt $receipt, bool $replaceLines): void;
}
