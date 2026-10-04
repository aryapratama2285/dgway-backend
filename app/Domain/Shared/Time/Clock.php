<?php

declare(strict_types=1);

namespace App\Domain\Shared\Time;

use DateTimeImmutable;

/**
 * Source of "now". Domain and application code never call time() / now()
 * directly, so time-dependent rules are testable with a frozen clock.
 */
interface Clock
{
    /** Current instant in UTC. */
    public function now(): DateTimeImmutable;

    /** Today's business date in the business time zone. */
    public function today(): BusinessDate;
}
