<?php

declare(strict_types=1);

namespace App\Domain\Partner;

/**
 * Roles a business partner can play towards DG Way. One partner may hold
 * several roles (a pengepul that sells eggs to DG Way and buys onions from it).
 * Future roles (CARRIER, MARKETPLACE_SELLER …) are added here.
 */
enum PartnerRole: string
{
    case SUPPLIER = 'SUPPLIER';
    case CUSTOMER = 'CUSTOMER';
}
