<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;

/**
 * Serialises credit decisions per customer.
 *
 * lock() takes a row lock on the customer's CUSTOMER role (held until the
 * transaction ends). Every credit sale confirmation for that customer takes
 * the same lock BEFORE reading the exposure, so two sales can never both
 * read the same remaining limit. Payments and returns only lower the
 * exposure, so they do not need this lock.
 */
interface CustomerCreditGate
{
    public function lock(Id $customerId): void;

    /** Σ outstanding of the customer's final sales (call after lock()). */
    public function outstandingOf(Id $customerId): Money;
}
