<?php

declare(strict_types=1);

namespace App\Domain\Partner;

/**
 * Commercial classification of a customer. A classification, not a table per
 * type: every customer shares the same structure.
 */
enum CustomerType: string
{
    case RETAIL = 'RETAIL';
    case UMKM = 'UMKM';
    case CATERING = 'CATERING';
    case RESTAURANT = 'RESTAURANT';
    case RESELLER = 'RESELLER';
    case CORPORATE = 'CORPORATE';
    case WALK_IN = 'WALK_IN';
    case OTHER = 'OTHER';
}
