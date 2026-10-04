<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Identity\Id;

interface SaleRepository
{
    public function get(Id $id): Sale;

    /** Loads the sale with a row lock: every change of its money or status goes through this lock. */
    public function getForUpdate(Id $id): Sale;

    public function add(Sale $sale, ?string $actorUserId = null): void;

    /** Persists status, money sums and line return counters (optimistic version check). */
    public function save(Sale $sale, ?string $actorUserId = null): void;
}
