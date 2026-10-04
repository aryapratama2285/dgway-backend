<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Shared\Identity\Id;

/**
 * Port: has this product been used by any transaction (purchase, stock,
 * sale …)? Used to protect rules such as "base UOM is locked after use".
 *
 * In A2 there are no transaction tables yet, so the answer is always "no";
 * from A3 on the adapter checks the transactional tables that reference
 * products.
 */
interface ProductUsageChecker
{
    public function isUsedInTransactions(Id $productId): bool;
}
