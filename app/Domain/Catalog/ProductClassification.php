<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/**
 * Primary economic / operational classification of an inventory-managed SKU.
 *
 * This is a classification, not a transaction permission matrix:
 * - SELLABLE: merchandise/trading goods primarily intended for sale.
 * - PACKAGING: packaging material used in fulfillment/handling.
 * - CONSUMABLE: operational supply consumed through normal business activity.
 *
 * Assets and other classes are intentionally deferred until required.
 */
enum ProductClassification: string
{
    case SELLABLE = 'SELLABLE';
    case PACKAGING = 'PACKAGING';
    case CONSUMABLE = 'CONSUMABLE';
}
