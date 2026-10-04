<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_defaults_to_idr_with_four_decimal_storage(): void
    {
        $m = Money::of('26000');

        $this->assertSame('IDR', $m->currency->code);
        $this->assertSame('26000.0000', $m->toStorage());
        $this->assertSame(['amount' => '26000.0000', 'currency' => 'IDR'], $m->jsonSerialize());
    }

    public function test_line_total_price_times_quantity_is_exact(): void
    {
        // 35 kg × Rp26.000/kg = Rp910.000 (the example sale in the brief)
        $total = Money::of('26000')->multipliedBy('35');

        $this->assertSame('910000.0000', $total->toStorage());
    }

    public function test_fractional_quantity_line_total(): void
    {
        // 12.345 kg × Rp27.500 = Rp339.487,5
        $this->assertSame('339487.5000', Money::of('27500')->multipliedBy('12.345')->toStorage());
    }

    public function test_effective_hpp_per_sellable_kg_from_yield(): void
    {
        // Purchase 150 kg × Rp24.000 + transport Rp150.000 = Rp3.750.000
        // accepted 140 kg → Rp26.785,7143 per sellable kg (not Rp25.000)
        $landed = Money::of('24000')->multipliedBy('150')->plus(Money::of('150000'));
        $perKg = $landed->dividedBy('140');

        $this->assertSame('3750000.0000', $landed->toStorage());
        $this->assertSame('26785.7143', $perKg->toStorage());
    }

    public function test_receivable_after_partial_payment(): void
    {
        $receivable = Money::of('1000000')->minus(Money::of('700000'));

        $this->assertSame('300000.0000', $receivable->toStorage());
        $this->assertTrue($receivable->isPositive());
    }

    public function test_rounding_to_whole_rupiah_is_explicit_and_half_up(): void
    {
        $this->assertSame('339488.0000', Money::of('339487.5')->roundedToCurrencyUnit()->toStorage());
        $this->assertSame('339487.0000', Money::of('339487.4999')->roundedToCurrencyUnit()->toStorage());
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->expectException(InvariantViolation::class);
        Money::of('1')->plus(Money::of('1', Currency::of('USD')));
    }

    public function test_more_than_four_decimals_is_rejected_not_silently_rounded(): void
    {
        $this->expectException(InvariantViolation::class);
        Money::of('1.00001');
    }

    public function test_assert_non_negative(): void
    {
        Money::zero()->assertNonNegative();
        $this->expectException(InvariantViolation::class);
        Money::of('-1')->assertNonNegative('Payment');
    }

    public function test_comparisons_and_immutability(): void
    {
        $a = Money::of('100');
        $b = $a->plus(Money::of('50'));

        $this->assertSame('100.0000', $a->toStorage(), 'original instance must not change');
        $this->assertTrue($b->isGreaterThan($a));
        $this->assertTrue($a->isLessThan($b));
        $this->assertSame(0, Money::of('100.0')->compareTo($a));
        $this->assertTrue(Money::of('100.00')->equals($a));
        $this->assertTrue($a->minus($a)->isZero());
        $this->assertTrue($a->negated()->isNegative());
    }
}
