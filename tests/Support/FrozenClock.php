<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** Deterministic clock for tests. */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $utc = '2026-10-03T01:00:00Z', private readonly string $timezone = 'Asia/Jakarta')
    {
        $this->now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function today(): BusinessDate
    {
        return BusinessDate::fromInstant($this->now, $this->timezone);
    }

    public function travelTo(string $utc): void
    {
        $this->now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }
}
