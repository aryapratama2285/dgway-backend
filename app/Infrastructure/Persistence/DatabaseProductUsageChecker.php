<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Catalog\ProductUsageChecker;
use App\Domain\Shared\Identity\Id;
use Illuminate\Database\ConnectionInterface;

/**
 * Checks the transactional tables that reference products.
 *
 * The table list comes from config('dgway.product_reference_tables'). It is
 * a list of "table.column" entries (a table may reference products through
 * more than one column). Keeping the list in one place means new transaction
 * tables cannot be forgotten silently: ProductReferenceRegistryTest asserts
 * every foreign key to products.id outside master data is listed.
 */
final class DatabaseProductUsageChecker implements ProductUsageChecker
{
    /** @param list<string> $references "table.column" */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly array $references,
    ) {
    }

    public function isUsedInTransactions(Id $productId): bool
    {
        foreach ($this->references as $reference) {
            [$table, $column] = explode('.', $reference, 2);
            if ($this->db->table($table)->where($column, $productId->value)->exists()) {
                return true;
            }
        }

        return false;
    }
}
