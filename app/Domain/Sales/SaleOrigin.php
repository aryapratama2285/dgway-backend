<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * Where a sale comes from. A4 creates DIRECT sales only (counter / WhatsApp,
 * no customer order needed). ORDER is the extension point for the future
 * Customer Order → Fulfillment → Sale flow: such a sale carries the order's
 * id in `orderReferenceId`; everything after confirmation is identical.
 */
enum SaleOrigin: string
{
    case DIRECT = 'DIRECT';
    case ORDER = 'ORDER';
}
