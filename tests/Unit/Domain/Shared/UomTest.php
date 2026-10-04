<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Uom;
use PHPUnit\Framework\TestCase;

final class UomTest extends TestCase
{
    public function test_baseline_units(): void
    {
        $this->assertSame(['KG', 3], [Uom::kg()->code, Uom::kg()->precision]);
        $this->assertSame(['PCS', 0], [Uom::pcs()->code, Uom::pcs()->precision]);
        $this->assertSame(['IKAT', 0], [Uom::ikat()->code, Uom::ikat()->precision]);
    }

    public function test_new_units_can_be_defined_without_code_changes(): void
    {
        $tray = Uom::of('tray', 0);

        $this->assertSame('TRAY', $tray->code);
        $this->assertTrue($tray->equals(Uom::of('TRAY', 0)));
        $this->assertFalse($tray->equals(Uom::pcs()));
    }

    public function test_invalid_code_rejected(): void
    {
        $this->expectException(InvariantViolation::class);
        Uom::of('1KG', 0);
    }

    public function test_precision_bounds(): void
    {
        $this->expectException(InvariantViolation::class);
        Uom::of('LITER', 5);
    }
}
