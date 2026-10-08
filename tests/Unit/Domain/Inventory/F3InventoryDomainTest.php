<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory;

use App\Domain\Inventory\AdjustmentReason;
use App\Domain\Inventory\ConsumptionReason;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\InventoryOperation;
use App\Domain\Inventory\InventoryOperationType;
use App\Domain\Inventory\LotPortion;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\ReorderPoint;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Procurement\PurchaseOrderTest;

final class F3InventoryDomainTest extends TestCase
{
    private function pcs(string $v): Quantity
    {
        return Quantity::of($v, Uom::pcs());
    }

    private function kg(string $v): Quantity
    {
        return Quantity::of($v, Uom::kg());
    }

    private function consumption(string $qty = '6', ?string $notes = null, ?string $ref = null, ConsumptionReason $why = ConsumptionReason::PACKING): InventoryOperation
    {
        return InventoryOperation::internalConsumption(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-001'), PurchaseOrderTest::id(), PurchaseOrderTest::id(),
            $this->pcs($qty), $why, $notes, $ref, BusinessDate::fromString('2026-10-03'), new DateTimeImmutable());
    }

    private function adjustment(string $qty, Direction $dir, ?Quantity $counted = null, ?Quantity $system = null): InventoryOperation
    {
        return InventoryOperation::adjustment(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-002'), PurchaseOrderTest::id(), PurchaseOrderTest::id(),
            $this->kg($qty), $dir, 'stock opname', BusinessDate::fromString('2026-10-03'), new DateTimeImmutable(), AdjustmentReason::COUNT_CORRECTION, $counted, $system);
    }

    public function test_internal_consumption_is_its_own_out_only_type(): void
    {
        $this->assertSame([Direction::OUT], MovementType::INTERNAL_CONSUMPTION->allowedDirections());
        $this->assertSame('inventory.internal_consumption', InventoryOperationType::INTERNAL_CONSUMPTION->auditAction());

        $op = $this->consumption('6', null, '  WA  0042 ');
        $this->assertTrue($op->consumesLots());
        $this->assertSame(['PACKING', 'PACKING', 'WA 0042'], [$op->reason, $op->reasonCode, $op->referenceText], 'without notes the code is the reason text');
        $this->assertSame('kemas order', $this->consumption('1', ' kemas order ')->reason);

        $lotA = PurchaseOrderTest::id();
        $lotB = PurchaseOrderTest::id();
        $movements = $op->movements(fn () => PurchaseOrderTest::id(), null, [new LotPortion($lotA, $this->pcs('4')), new LotPortion($lotB, $this->pcs('2'))]);
        $this->assertSame([MovementType::INTERNAL_CONSUMPTION, MovementType::INTERNAL_CONSUMPTION], array_map(fn ($m) => $m->type, $movements));
        $this->assertSame([Direction::OUT, Direction::OUT], array_map(fn ($m) => $m->direction, $movements));
    }

    public function test_consumption_reference_and_quantity_guards(): void
    {
        $this->assertNull($this->consumption('1', null, '   ')->referenceText, 'blank reference = none');
        try {
            $this->consumption('1', null, str_repeat('x', 61));
            $this->fail('long reference accepted');
        } catch (InvariantViolation $e) {
            $this->assertSame('REFERENCE_TEXT_INVALID', $e->errorCode());
        }
        $this->expectException(InvariantViolation::class);
        $this->consumption('0');
    }

    public function test_count_snapshot_must_match_the_posted_delta(): void
    {
        $op = $this->adjustment('0.2', Direction::OUT, $this->kg('14.8'), $this->kg('15'));
        $this->assertSame(['14.8000', '15.0000', 'COUNT_CORRECTION'], [$op->countedQuantity?->toStorage(), $op->systemQuantity?->toStorage(), $op->reasonCode]);
        $this->assertSame('ADJUSTMENT', $this->adjustment('1', Direction::IN)->type->value, 'delta mode unchanged');

        foreach ([
            fn () => $this->adjustment('0.3', Direction::OUT, $this->kg('14.8'), $this->kg('15')),  // wrong delta
            fn () => $this->adjustment('0.2', Direction::IN, $this->kg('14.8'), $this->kg('15')),   // wrong direction
            fn () => $this->adjustment('0.2', Direction::OUT, $this->kg('14.8'), null),             // incomplete snapshot
        ] as $bad) {
            try {
                $bad();
                $this->fail('inconsistent snapshot accepted');
            } catch (InvariantViolation $e) {
                $this->assertStringStartsWith('COUNT_SNAPSHOT_', $e->errorCode());
            }
        }
    }

    public function test_reorder_point_is_in_the_base_uom_and_null_disables(): void
    {
        $p = ReorderPoint::create(PurchaseOrderTest::id(), PurchaseOrderTest::id(), PurchaseOrderTest::id(), Uom::pcs(), $this->pcs('10'));
        $this->assertFalse($p->set($this->pcs('10')), 'same value is no change');
        $this->assertTrue($p->set(null));
        $this->assertNull($p->minQuantity());
        $this->assertSame(['product_id', 'node_id', 'min_quantity'], array_keys($p->snapshot()));

        $this->expectException(InvariantViolation::class);
        $p->set($this->kg('1')); // another unit is refused
    }

    public function test_reorder_point_cannot_be_negative(): void
    {
        $this->expectException(InvariantViolation::class);
        ReorderPoint::create(PurchaseOrderTest::id(), PurchaseOrderTest::id(), PurchaseOrderTest::id(), Uom::pcs(), $this->pcs('-1'));
    }

    public function test_reason_codes_are_small_and_stable(): void
    {
        $this->assertSame(['PACKING', 'LABELING', 'DELIVERY', 'ADMIN', 'SAMPLE', 'OTHER'], array_map(fn ($c) => $c->value, ConsumptionReason::cases()));
        $this->assertSame(['COUNT_CORRECTION', 'FOUND_STOCK', 'SHRINKAGE', 'OTHER'], array_map(fn ($c) => $c->value, AdjustmentReason::cases()));
    }
}
