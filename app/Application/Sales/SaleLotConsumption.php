<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Domain\Shared\Identity\Id;

/**
 * Read port: which lots a sale line consumed, and how much of each has
 * already come back through customer returns — derived from the ledger
 * (SALE / REVERSAL / CUSTOMER_RETURN movements), never stored separately.
 * Pre-lot sales map to the LEGACY lot of their product/node.
 */
interface SaleLotConsumption
{
    /**
     * @return list<array{lot_id: string, lot_number: string, consumed: string, returned: string}>
     *         in allocation order (oldest SALE movement first)
     */
    public function ofSaleLine(Id $saleLineId): array;
}
