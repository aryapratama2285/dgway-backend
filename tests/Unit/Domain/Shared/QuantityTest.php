<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    public function test_kg_quantity_storage_and_json(): void
    {
        $q = Quantity::of('35', Uom::kg());

        $this->assertSame('35.0000', $q->toStorage());
        $this->assertSame(['value' => '35.0000', 'uom' => 'KG'], $q->jsonSerialize());
    }

    public function test_received_minus_accepted_equals_rejected(): void
    {
        $received = Quantity::of('150', Uom::kg());
        $accepted = Quantity::of('140', Uom::kg());

        $this->assertTrue($received->minus($accepted)->equals(Quantity::of('10', Uom::kg())));
    }

    public function test_partial_fulfillment_remaining_quantity(): void
    {
        $ordered = Quantity::of('35', Uom::kg());
        $fulfilled = Quantity::of('30', Uom::kg());

        $this->assertSame('5.0000', $ordered->minus($fulfilled)->toStorage());
        $this->assertSame('35.0000', $ordered->toStorage(), 'ordered quantity is never mutated');
    }

    public function test_kg_allows_gram_precision(): void
    {
        $this->assertSame('12.3450', Quantity::of('12.345', Uom::kg())->toStorage());
    }

    public function test_kg_rejects_sub_gram_precision(): void
    {
        $this->expectException(InvariantViolation::class);
        Quantity::of('12.3456', Uom::kg());
    }

    public function test_pcs_rejects_fractions(): void
    {
        $this->expectException(InvariantViolation::class);
        Quantity::of('1.5', Uom::pcs());
    }

    public function test_pcs_accepts_whole_number_written_with_trailing_zeros(): void
    {
        $this->assertSame('3.0000', Quantity::of('3.00', Uom::pcs())->toStorage());
    }

    public function test_units_are_never_mixed_implicitly(): void
    {
        $this->expectException(InvariantViolation::class);
        Quantity::of('1', Uom::kg())->plus(Quantity::of('1', Uom::pcs()));
    }

    public function test_assert_positive_rejects_zero(): void
    {
        $this->expectException(InvariantViolation::class);
        Quantity::zero(Uom::kg())->assertPositive('Allocated quantity');
    }

    public function test_negative_quantity_allowed_as_value_but_guarded(): void
    {
        $variance = Quantity::of('98', Uom::kg())->minus(Quantity::of('100', Uom::kg()));
        $this->assertTrue($variance->isNegative());

        $this->expectException(InvariantViolation::class);
        $variance->assertNonNegative('Stock');
    }

    public function test_comparisons(): void
    {
        $a = Quantity::of('20', Uom::kg());
        $b = Quantity::of('30', Uom::kg());

        $this->assertTrue($a->isLessThan($b));
        $this->assertTrue($b->isGreaterThan($a));
        $this->assertSame(-1, $a->compareTo($b));
    }
}
