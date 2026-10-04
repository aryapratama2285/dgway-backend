<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory;

use App\Domain\Inventory\Direction;
use App\Domain\Inventory\DisallowNegativeStock;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryOperation;
use App\Domain\Inventory\MovementType;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockBucket;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockMovement;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Procurement\PurchaseOrderTest;

final class InventoryDomainTest extends TestCase
{
    private Id $product;
    private Id $node;

    protected function setUp(): void
    {
        $this->product = PurchaseOrderTest::id();
        $this->node = PurchaseOrderTest::id();
    }

    private function kg(string $v): Quantity
    {
        return Quantity::of($v, Uom::kg());
    }

    private function key(StockBucket $b = StockBucket::AVAILABLE, ?Id $product = null): StockKey
    {
        return new StockKey($product ?? $this->product, $this->node, $b);
    }

    private function move(MovementType $t, Direction $d, string $qty, ?StockKey $key = null, ?Id $reverses = null): StockMovement
    {
        return StockMovement::record(PurchaseOrderTest::id(), $t, $key ?? $this->key(), $d, $this->kg($qty), BusinessDate::fromString('2026-10-03'),
            new DateTimeImmutable(), new SourceDocument('goods_receipt', PurchaseOrderTest::id()), PurchaseOrderTest::id(), null, null, null, $reverses);
    }

    public function test_every_movement_type_declares_its_directions(): void
    {
        foreach (MovementType::cases() as $type) {
            $this->assertNotEmpty($type->allowedDirections(), $type->value);
        }
        $this->assertTrue(MovementType::RECEIPT->allows(Direction::IN));
        $this->assertFalse(MovementType::RECEIPT->allows(Direction::OUT));
        $this->assertFalse(MovementType::LOSS->allows(Direction::IN));
        $this->assertTrue(MovementType::SALE->allows(Direction::OUT), 'ready for A4');
    }

    public function test_movement_rejects_wrong_direction_zero_or_negative_quantity(): void
    {
        foreach ([
            fn () => $this->move(MovementType::RECEIPT, Direction::OUT, '1'),
            fn () => $this->move(MovementType::LOSS, Direction::IN, '1'),
            fn () => $this->move(MovementType::RECEIPT, Direction::IN, '0'),
            fn () => $this->move(MovementType::RECEIPT, Direction::IN, '-5'),
            fn () => $this->move(MovementType::REVERSAL, Direction::OUT, '1'),                        // reversal without link
            fn () => $this->move(MovementType::LOSS, Direction::OUT, '1', reverses: PurchaseOrderTest::id()), // link on non-reversal
        ] as $bad) {
            try {
                $bad();
                $this->fail('invalid movement created');
            } catch (InvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reversal_mirrors_the_original_and_cannot_be_reversed_again(): void
    {
        $receipt = $this->move(MovementType::RECEIPT, Direction::IN, '150');
        $reversal = $receipt->reverse(PurchaseOrderTest::id(), BusinessDate::fromString('2026-10-04'), new DateTimeImmutable(), new SourceDocument('goods_receipt', PurchaseOrderTest::id()), PurchaseOrderTest::id(), null, 'salah input');

        $this->assertSame(MovementType::REVERSAL, $reversal->type);
        $this->assertSame(Direction::OUT, $reversal->direction);
        $this->assertTrue($reversal->quantity->equals($receipt->quantity));
        $this->assertTrue($reversal->reversesMovementId?->equals($receipt->id));
        $this->assertSame('-150.0000', $reversal->signedQuantity()->toStorage());

        $this->expectExceptionMessage('cannot itself be reversed');
        $reversal->reverse(PurchaseOrderTest::id(), BusinessDate::fromString('2026-10-04'), new DateTimeImmutable(), new SourceDocument('goods_receipt', PurchaseOrderTest::id()), PurchaseOrderTest::id(), null, 'x');
    }

    public function test_balance_rejects_going_negative(): void
    {
        $balance = StockBalance::of($this->key(), $this->kg('5'));

        try {
            $balance->apply($this->move(MovementType::LOSS, Direction::OUT, '10'), new DisallowNegativeStock());
            $this->fail();
        } catch (InsufficientStock $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode());
            $this->assertSame('5.0000', $e->context()['available']);
        }
        $this->assertSame('5.0000', $balance->quantity()->toStorage(), 'unchanged after refusal');

        $balance->apply($this->move(MovementType::LOSS, Direction::OUT, '5'), new DisallowNegativeStock());
        $this->assertTrue($balance->quantity()->isZero(), 'exactly zero is allowed');
    }

    public function test_balance_rejects_movements_of_another_position(): void
    {
        $this->expectExceptionMessage('does not belong');
        StockBalance::of($this->key(StockBucket::AVAILABLE), $this->kg('5'))
            ->apply($this->move(MovementType::RECEIPT, Direction::IN, '1', $this->key(StockBucket::QUARANTINE)), new DisallowNegativeStock());
    }

    /** Property: for random IN/OUT sequences the balance equals the signed ledger sum and never goes below zero. */
    public function test_balance_equals_ledger_sum_and_never_negative_property(): void
    {
        mt_srand(42);
        for ($run = 0; $run < 50; $run++) {
            $balance = StockBalance::of($this->key(), $this->kg('0'));
            $ledgerGrams = 0;
            for ($i = 0; $i < 60; $i++) {
                $grams = mt_rand(1, 50_000);
                $in = mt_rand(0, 2) > 0;
                $m = $this->move($in ? MovementType::RECEIPT : MovementType::LOSS, $in ? Direction::IN : Direction::OUT, sprintf('%d.%03d', intdiv($grams, 1000), $grams % 1000));
                try {
                    $balance->apply($m, new DisallowNegativeStock());
                    $ledgerGrams += $in ? $grams : -$grams;
                } catch (InsufficientStock) {
                    $this->assertGreaterThan($ledgerGrams, $grams, 'refused only when it would go negative');
                }
                $this->assertGreaterThanOrEqual(0, $ledgerGrams);
                $this->assertSame(sprintf('%d.%03d0', intdiv($ledgerGrams, 1000), $ledgerGrams % 1000), $balance->quantity()->toStorage());
            }
        }
    }

    public function test_regrade_operation_produces_conserving_pair_with_one_correlation(): void
    {
        $afkir = PurchaseOrderTest::id();
        $op = InventoryOperation::regrade(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-001'), $this->node, $this->product, $afkir, $this->kg('5'), 'retak rambut', BusinessDate::fromString('2026-10-03'), new DateTimeImmutable());

        $lotA = PurchaseOrderTest::id();
        $derivedB = PurchaseOrderTest::id();
        [$out, $in] = $op->movements(fn () => PurchaseOrderTest::id(), null, [new \App\Domain\Inventory\LotPortion($lotA, $this->kg('5'))], [$lotA->value => $derivedB]);

        $this->assertSame([MovementType::REGRADE_OUT, MovementType::REGRADE_IN], [$out->type, $in->type]);
        $this->assertTrue($out->key->productId->equals($this->product));
        $this->assertTrue($in->key->productId->equals($afkir));
        $this->assertTrue($out->correlationId->equals($in->correlationId));
        $this->assertTrue($out->signedQuantity()->plus($in->signedQuantity())->isZero(), 'quantity conserved');
        $this->assertTrue($out->lotId->equals($lotA), 'taken from the parent lot');
        $this->assertTrue($in->lotId->equals($derivedB), 'put into the derived lot');

        // the lot portions must account for exactly the operation quantity (A5-B; replaces the A3 unit-conversion check,
        // which is now enforced before the operation is built: products with different base UOM cannot be regraded)
        $this->expectExceptionMessage('Lot portions total');
        $op->movements(fn () => PurchaseOrderTest::id(), null, [new \App\Domain\Inventory\LotPortion($lotA, $this->kg('4'))], [$lotA->value => $derivedB]);
    }

    public function test_recovery_moves_from_non_sellable_to_available(): void
    {
        $op = InventoryOperation::recovery(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-002'), $this->node, $this->product, $this->kg('3'), StockBucket::RETURN_PENDING, 'supplier menolak retur, barang masih baik', BusinessDate::fromString('2026-10-03'), new DateTimeImmutable());
        $lot = PurchaseOrderTest::id();
        [$out, $in] = $op->movements(fn () => PurchaseOrderTest::id(), null, [new \App\Domain\Inventory\LotPortion($lot, $this->kg('3'))]);

        $this->assertSame(StockBucket::RETURN_PENDING, $out->key->bucket);
        $this->assertSame(StockBucket::AVAILABLE, $in->key->bucket);
        $this->assertTrue($out->lotId->equals($lot) && $in->lotId->equals($lot), 'recovery keeps the lot, only the bucket changes');

        $this->expectExceptionMessage('non-sellable');
        InventoryOperation::recovery(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-003'), $this->node, $this->product, $this->kg('3'), StockBucket::AVAILABLE, 'x', BusinessDate::fromString('2026-10-03'), new DateTimeImmutable());
    }

    public function test_operations_require_a_reason(): void
    {
        $this->expectExceptionMessage('needs a reason');
        InventoryOperation::loss(PurchaseOrderTest::id(), DocumentNumber::parse('INV-261003-004'), $this->node, $this->product, $this->kg('1'), '  ', BusinessDate::fromString('2026-10-03'), new DateTimeImmutable());
    }
}
