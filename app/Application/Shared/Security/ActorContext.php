<?php

declare(strict_types=1);

namespace App\Application\Shared\Security;

use App\Domain\Shared\Identity\Actor;

/**
 * Resolves the actor performing the current use case
 * (the authenticated operator for API calls, the system otherwise).
 */
interface ActorContext
{
    public function current(): Actor;
}
