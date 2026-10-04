<?php

declare(strict_types=1);

namespace App\Application\Shared\Document;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Time\BusinessDate;

/**
 * Issues the next document number for a prefix on a business date.
 *
 * Must be called inside a transaction: if the surrounding transaction rolls
 * back, the number is released, so committed documents have no gaps caused by
 * failed transactions.
 */
interface DocumentNumberGenerator
{
    public function next(string $prefix, BusinessDate $date): DocumentNumber;
}
