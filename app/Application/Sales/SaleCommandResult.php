<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Domain\Sales\CustomerReturn;
use App\Domain\Sales\Payment;
use App\Domain\Sales\Sale;
use App\Domain\Shared\Identity\Id;

/**
 * Result of a command that creates a payment or return: the new document plus
 * the sale after the change (so the client sees the new outstanding at once).
 */
final class SaleCommandResult
{
    public readonly Id $id;

    public function __construct(
        public readonly Sale $sale,
        public readonly Payment|CustomerReturn $document,
    ) {
        $this->id = $document->id();
    }
}
