<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Procurement;

use App\Domain\Procurement\InvalidTransition;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Procurement\PurchaseOrderStatus as S;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderTest extends TestCase
{
    private static int $n = 0;

    public static function id(): Id
    {
        return Id::fromString(sprintf('01928a3c-0000-7000-8000-%012d', ++self::$n));
    }

    private function line(string $qty = '500', string $cost = '23000', int $no = 1): PurchaseOrderLine
    {
        return PurchaseOrderLine::create(self::id(), $no, self::id(), Quantity::of($qty, Uom::kg()), Money::of($cost));
    }

    private function po(?PurchaseOrderLine $line = null): PurchaseOrder
    {
        return PurchaseOrder::draft(self::id(), DocumentNumber::parse('PO-261003-001'), self::id(), self::id(), BusinessDate::fromString('2026-10-03'), [$line ?? $this->line()]);
    }

    private function kg(string $v): Quantity
    {
        return Quantity::of($v, Uom::kg());
    }

    public function test_line_total_uses_money_precision(): void
    {
        $this->assertSame('3450000.0000', $this->line('150', '23000')->lineTotal()->toStorage());
        $this->assertSame('339487.5000', $this->line('12.345', '27500')->lineTotal()->toStorage());
        $this->assertSame('11500000.0000', $this->po()->totalAmount()->toStorage());
    }

    public function test_partial_then_full_receipt_moves_status(): void
    {
        $line = $this->line('500');
        $po = $this->po($line);
        $po->confirm();

        $po->recordReceipt($line->id, $this->kg('200'));
        $this->assertSame(S::PARTIALLY_RECEIVED, $po->status());
        $po->recordReceipt($line->id, $this->kg('150'));
        $this->assertSame(S::PARTIALLY_RECEIVED, $po->status());
        $po->recordReceipt($line->id, $this->kg('150'));
        $this->assertSame(S::RECEIVED, $po->status());
        $this->assertSame('500.0000', $line->receivedQuantity()->toStorage());
    }

    public function test_over_receipt_is_rejected(): void
    {
        $line = $this->line('500');
        $po = $this->po($line);
        $po->confirm();
        $po->recordReceipt($line->id, $this->kg('400'));

        try {
            $po->recordReceipt($line->id, $this->kg('100.001'));
            $this->fail('over-receipt accepted');
        } catch (InvariantViolation $e) {
            $this->assertSame('OVER_RECEIPT', $e->errorCode());
        }
        $this->assertSame('400.0000', $line->receivedQuantity()->toStorage());
    }

    public function test_draft_cannot_receive(): void
    {
        $line = $this->line();
        $this->expectException(InvalidTransition::class);
        $this->po($line)->recordReceipt($line->id, $this->kg('1'));
    }

    public function test_cancelled_cannot_be_received_or_confirmed(): void
    {
        $line = $this->line();
        $po = $this->po($line);
        $po->cancel('supplier tutup');

        foreach ([fn () => $po->recordReceipt($line->id, $this->kg('1')), fn () => $po->confirm()] as $op) {
            try {
                $op();
                $this->fail('transition from CANCELLED accepted');
            } catch (InvalidTransition $e) {
                $this->assertSame('INVALID_STATUS_TRANSITION', $e->errorCode());
            }
        }
        $this->assertSame(S::CANCELLED, $po->status());
    }

    public function test_cannot_cancel_after_receipts(): void
    {
        $line = $this->line();
        $po = $this->po($line);
        $po->confirm();
        $po->recordReceipt($line->id, $this->kg('10'));

        $this->expectExceptionMessage('cannot be cancelled');
        $po->cancel('x');
    }

    public function test_receipt_reversal_moves_status_back(): void
    {
        $line = $this->line('100');
        $po = $this->po($line);
        $po->confirm();
        $po->recordReceipt($line->id, $this->kg('100'));
        $this->assertSame(S::RECEIVED, $po->status());

        $po->reverseReceipt($line->id, $this->kg('40'));
        $this->assertSame(S::PARTIALLY_RECEIVED, $po->status());
        $po->reverseReceipt($line->id, $this->kg('60'));
        $this->assertSame(S::CONFIRMED, $po->status());
    }

    /** @return iterable<string, array{S, S, bool}> */
    public static function transitions(): iterable
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $allowed = match ($from) {
                    S::DRAFT => in_array($to, [S::CONFIRMED, S::CANCELLED], true),
                    S::CONFIRMED => in_array($to, [S::PARTIALLY_RECEIVED, S::RECEIVED, S::CANCELLED], true),
                    S::PARTIALLY_RECEIVED => in_array($to, [S::PARTIALLY_RECEIVED, S::RECEIVED, S::CONFIRMED, S::CLOSED], true),
                    S::RECEIVED => in_array($to, [S::PARTIALLY_RECEIVED, S::CONFIRMED, S::CLOSED], true),
                    // F4A: CLOSED is terminal like CANCELLED
                    S::CLOSED, S::CANCELLED => false,
                };
                yield "{$from->value} → {$to->value}" => [$from, $to, $allowed];
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_state_machine_transition_table(S $from, S $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_draft_validation(): void
    {
        foreach ([
            fn () => PurchaseOrder::draft(self::id(), DocumentNumber::parse('PO-261003-002'), self::id(), self::id(), BusinessDate::fromString('2026-10-03'), []),
            fn () => $this->line('0'),
            fn () => $this->line('5', '-1'),
        ] as $bad) {
            try {
                $bad();
                $this->fail('invalid PO accepted');
            } catch (InvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
