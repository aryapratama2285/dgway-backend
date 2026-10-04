<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Uom;

/** Resolves a UOM code sent by a client to an active unit of measure. */
final class UomResolver
{
    public function __construct(private readonly UnitOfMeasureRepository $uoms)
    {
    }

    public function activeOrFail(string $code, string $field = 'base_uom'): Uom
    {
        return $this->uoms->findActive(strtoupper(trim($code)))
            ?? throw InvariantViolation::because('UOM_UNKNOWN', "Unit of measure '{$code}' does not exist or is inactive.", ['field' => $field]);
    }
}
