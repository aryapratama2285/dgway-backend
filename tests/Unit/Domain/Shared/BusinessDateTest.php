<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BusinessDateTest extends TestCase
{
    public function test_early_morning_wib_belongs_to_the_local_day_not_the_utc_day(): void
    {
        // 2026-10-02 23:30 UTC = 2026-10-03 06:30 WIB → business day is 3 Oct.
        $instant = new DateTimeImmutable('2026-10-02T23:30:00Z');

        $this->assertSame('2026-10-03', BusinessDate::fromInstant($instant)->toString());
    }

    public function test_late_evening_wib_stays_on_same_local_day(): void
    {
        // 2026-10-03 16:59 UTC = 23:59 WIB on 3 Oct.
        $this->assertSame('2026-10-03', BusinessDate::fromInstant(new DateTimeImmutable('2026-10-03T16:59:00Z'))->toString());
        // 17:00 UTC = 00:00 WIB on 4 Oct.
        $this->assertSame('2026-10-04', BusinessDate::fromInstant(new DateTimeImmutable('2026-10-03T17:00:00Z'))->toString());
    }

    public function test_compact_form_for_document_numbers(): void
    {
        $this->assertSame('261003', BusinessDate::fromString('2026-10-03')->compact());
    }

    public function test_invalid_dates_rejected(): void
    {
        foreach (['2026-02-30', '2026-13-01', '03-10-2026', '2026-10-3', ''] as $bad) {
            try {
                BusinessDate::fromString($bad);
                $this->fail("Expected '{$bad}' to be rejected");
            } catch (InvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_comparison_and_arithmetic(): void
    {
        $d = BusinessDate::fromString('2026-10-31');

        $this->assertSame('2026-11-01', $d->addDays(1)->toString());
        $this->assertSame('2026-10-30', $d->addDays(-1)->toString());
        $this->assertTrue($d->isBefore($d->addDays(1)));
        $this->assertTrue($d->equals(BusinessDate::fromString('2026-10-31')));
    }
}
