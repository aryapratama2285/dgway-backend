<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Math\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_classic_float_error_does_not_happen(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004 in floating point.
        $sum = Decimal::of('0.1')->plus(Decimal::of('0.2'));

        $this->assertSame('0.3', (string) $sum);
        $this->assertTrue($sum->isEqualTo(Decimal::of('0.3')));
    }

    public function test_division_rounds_half_up_at_requested_scale(): void
    {
        $this->assertSame('3.3333', (string) Decimal::of('10')->dividedBy(Decimal::of('3'), 4));
        $this->assertSame('0.6667', (string) Decimal::of('2')->dividedBy(Decimal::of('3'), 4));
        $this->assertSame('0.13', (string) Decimal::of('0.125')->roundedTo(2));
    }

    public function test_division_by_zero_is_a_domain_error(): void
    {
        $this->expectException(InvariantViolation::class);
        Decimal::of('1')->dividedBy(Decimal::zero(), 4);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'comma decimal' => ['1,5'];
        yield 'exponent' => ['1e3'];
        yield 'thousand separator' => ['1.000.000'];
        yield 'leading dot' => ['.5'];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_non_decimal_strings(string $input): void
    {
        $this->expectException(InvariantViolation::class);
        Decimal::of($input);
    }

    public function test_float_input_is_rejected_by_the_type_system(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line intentional misuse */
        Decimal::of(1.5);
    }

    public function test_to_fixed_pads_and_rounds(): void
    {
        $this->assertSame('35.0000', Decimal::of('35')->toFixed(4));
        $this->assertSame('1.2346', Decimal::of('1.23456')->toFixed(4));
    }
}
