<?php

declare(strict_types=1);

namespace App\Domain\Sales;

/**
 * What happens to goods a customer brings back. A return never adds
 * sellable stock by default: the operator decides.
 *
 * RETURN_TO_AVAILABLE   still sellable  → AVAILABLE
 * RETURN_TO_QUARANTINE  needs checking  → QUARANTINE (recover later via A3 operation)
 * LOSS                  e.g. broken eggs → enters QUARANTINE and is written off (LOSS)
 */
enum ReturnDisposition: string
{
    case RETURN_TO_AVAILABLE = 'RETURN_TO_AVAILABLE';
    case RETURN_TO_QUARANTINE = 'RETURN_TO_QUARANTINE';
    case LOSS = 'LOSS';
}
