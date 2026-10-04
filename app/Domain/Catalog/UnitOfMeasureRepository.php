<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Shared\Quantity\Uom;

/** Reference data: the units of measure DG Way uses (open set, `uoms` table). */
interface UnitOfMeasureRepository
{
    /** Returns the unit only if it exists and is active. */
    public function findActive(string $code): ?Uom;

    /** Any unit, active or not (for reading historical records). */
    public function find(string $code): ?Uom;
}
