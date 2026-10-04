<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function __construct(private readonly string $businessTimezone)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function today(): BusinessDate
    {
        return BusinessDate::fromInstant($this->now(), $this->businessTimezone);
    }
}
