<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * Marker for business errors where the request itself is well-formed but
 * conflicts with the CURRENT state of a document or balance (wrong status,
 * not enough stock, outstanding already paid, credit already used). The same
 * request may succeed later in another state. The API answers 409.
 */
interface ConflictsWithCurrentState
{
}
