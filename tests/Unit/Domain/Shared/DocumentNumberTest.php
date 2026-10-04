<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Time\BusinessDate;
use PHPUnit\Framework\TestCase;

final class DocumentNumberTest extends TestCase
{
    private BusinessDate $date;

    protected function setUp(): void
    {
        $this->date = BusinessDate::fromString('2026-10-03');
    }

    public function test_batch_code_format_from_the_brief(): void
    {
        $this->assertSame('EGG-261003-001', DocumentNumber::compose('EGG', $this->date, 1)->toString());
    }

    public function test_prefix_is_not_locked_to_eggs(): void
    {
        $this->assertSame('BWM-261003-007', DocumentNumber::compose('bwm', $this->date, 7)->toString());
        $this->assertSame('EGG-BDG-261003-012', DocumentNumber::compose('EGG-BDG', $this->date, 12)->toString());
        $this->assertSame('SO-261003-001', DocumentNumber::compose('SO', $this->date, 1)->toString());
    }

    public function test_sequence_grows_past_999_instead_of_overflowing(): void
    {
        $this->assertSame('SL-261003-1000', DocumentNumber::compose('SL', $this->date, 1000)->toString());
    }

    public function test_round_trip_parse(): void
    {
        foreach (['EGG-261003-001', 'EGG-BDG-261003-012', 'SL-261003-1000'] as $value) {
            $this->assertSame($value, DocumentNumber::parse($value)->toString());
        }

        $parsed = DocumentNumber::parse('EGG-BDG-261003-012');
        $this->assertSame('EGG-BDG', $parsed->prefix);
        $this->assertSame(12, $parsed->sequence);
        $this->assertSame('2026-10-03', $parsed->date->toString());
    }

    public function test_invalid_prefix_rejected(): void
    {
        $this->expectException(InvariantViolation::class);
        DocumentNumber::compose('EGG 1', $this->date, 1);
    }

    public function test_sequence_must_start_at_one(): void
    {
        $this->expectException(InvariantViolation::class);
        DocumentNumber::compose('SO', $this->date, 0);
    }

    public function test_unparseable_value_rejected(): void
    {
        $this->expectException(InvariantViolation::class);
        DocumentNumber::parse('EGG-2610-1');
    }
}
