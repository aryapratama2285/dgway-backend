<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Sales;

use App\Domain\Sales\CustomerAccount;
use App\Domain\Sales\Payment;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Sales\PaymentRequest;
use App\Domain\Sales\PaymentReversal;
use App\Domain\Sales\ReturnDisposition;
use App\Domain\Sales\ReturnLineRequest;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleLine;
use App\Domain\Sales\SaleStatus as S;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Exception\DomainException;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Exception\StateConflict;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaleTest extends TestCase
{
    private static int $n = 0;

    private static function id(): Id
    {
        return Id::fromString(sprintf('01928a3c-0000-7000-9000-%012d', ++self::$n));
    }

    private function kg(string $v): Quantity
    {
        return Quantity::of($v, Uom::kg());
    }

    private function line(string $qty = '35', string $price = '26000', ?string $discount = null, int $no = 1): SaleLine
    {
        return SaleLine::create(self::id(), $no, self::id(), $this->kg($qty), Money::of($price), $discount === null ? null : Money::of($discount));
    }

    /** @param list<SaleLine> $lines */
    private function sale(array $lines = [], ?Id $customer = null): Sale
    {
        return Sale::direct(self::id(), DocumentNumber::parse('SAL-261003-001'), $customer, self::id(), BusinessDate::fromString('2026-10-03'), $lines ?: [$this->line()]);
    }

    private function pay(string $amount, PaymentMethod $m = PaymentMethod::CASH): PaymentRequest
    {
        return new PaymentRequest(self::id(), DocumentNumber::parse('PAY-261003-001'), $m, Money::of($amount), new DateTimeImmutable('2026-10-03T03:00:00Z'), BusinessDate::fromString('2026-10-03'), null);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-03T02:00:00Z');
    }

    private function credit(Sale $sale, int $days = 7, ?string $limit = null): CustomerAccount
    {
        return CustomerAccount::customer($sale->customerId, $days, $limit === null ? null : Money::of($limit));
    }

    private function zero(): Money
    {
        return Money::zero();
    }

    private function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected {$code}");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    public function test_line_totals_are_exact(): void
    {
        $this->assertSame('910000.0000', $this->line('35', '26000')->total()->toStorage());
        $this->assertSame('2550000.0000', $this->line('100', '25500')->total()->toStorage());
        $this->assertSame('321123.3311', $this->line('12.345', '26012.4205')->subtotal()->toStorage()); // 321 123.33107… → half-up
        $sale = $this->sale([$this->line('35', '26000', '10000'), $this->line('2', '27000', null, 2)]);
        $this->assertSame('964000.0000', $sale->subtotal()->toStorage());
        $this->assertSame('10000.0000', $sale->discountTotal()->toStorage());
        $this->assertSame('954000.0000', $sale->grandTotal()->toStorage());
    }

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $this->expectCode('DISCOUNT_EXCEEDS_SUBTOTAL', fn () => $this->line('1', '26000', '26000.0001'));
        $this->assertSame('0.0000', $this->line('1', '26000', '26000')->total()->toStorage());
    }

    /** @return iterable<string, array{S, S, bool}> */
    public static function transitions(): iterable
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $allowed = in_array([$from, $to], [[S::DRAFT, S::CONFIRMED], [S::DRAFT, S::COMPLETED], [S::DRAFT, S::CANCELLED],
                    [S::CONFIRMED, S::COMPLETED], [S::CONFIRMED, S::REVERSED], [S::COMPLETED, S::CONFIRMED], [S::COMPLETED, S::REVERSED]], true);
                yield "{$from->value} → {$to->value}" => [$from, $to, $allowed];
            }
        }
    }

    #[DataProvider('transitions')]
    public function test_state_machine_table(S $from, S $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_walk_in_must_pay_in_full(): void
    {
        $sale = $this->sale([$this->line('2', '27000')]);
        $this->expectCode('CREDIT_NOT_ALLOWED', fn () => $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('50000')]));

        $sale = $this->sale([$this->line('2', '27000')]);
        $payments = $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('54000')]);
        $this->assertCount(1, $payments);
        $this->assertSame(S::COMPLETED, $sale->status());
        $this->assertSame('0.0000', $sale->outstanding()->toStorage());
        $this->assertSame('2026-10-03', $sale->dueDate()?->toString());
    }

    public function test_cash_customer_cannot_owe_but_credit_customer_can(): void
    {
        $sale = $this->sale([], self::id());
        $this->expectCode('CREDIT_NOT_ALLOWED', fn () => $sale->confirm($this->now(), $this->credit($sale, 0), $this->zero()));

        $sale = $this->sale([], self::id());
        $sale->confirm($this->now(), $this->credit($sale, 7), $this->zero(), [$this->pay('400000')]);
        $this->assertSame(S::CONFIRMED, $sale->status());
        $this->assertSame('510000.0000', $sale->outstanding()->toStorage());
        $this->assertSame('2026-10-10', $sale->dueDate()?->toString());
        $this->assertSame('PARTIALLY_PAID', $sale->paymentStatus());
    }

    public function test_credit_limit_boundary(): void
    {
        // 910 000 sale, limit 1 000 000, already owed 90 000 → exactly at the limit: allowed
        $sale = $this->sale([], self::id());
        $sale->confirm($this->now(), $this->credit($sale, 7, '1000000'), Money::of('90000'));
        $this->assertSame(S::CONFIRMED, $sale->status());

        $sale = $this->sale([], self::id());
        try {
            $sale->confirm($this->now(), $this->credit($sale, 7, '1000000'), Money::of('90000.0001'));
            $this->fail('expected CREDIT_LIMIT_EXCEEDED');
        } catch (StateConflict $e) {
            $this->assertSame('CREDIT_LIMIT_EXCEEDED', $e->errorCode());
            $this->assertSame('909999.9999', $e->context()['available_credit']);
        }

        // paying at the counter reduces the credit needed
        $sale = $this->sale([], self::id());
        $sale->confirm($this->now(), $this->credit($sale, 7, '100000'), Money::of('0'), [$this->pay('810000')]);
        $this->assertSame('100000.0000', $sale->outstanding()->toStorage());
    }

    public function test_partial_payments_and_overpayment(): void
    {
        $sale = $this->sale([$this->line('40', '25000')], self::id()); // 1 000 000
        $sale->confirm($this->now(), $this->credit($sale), $this->zero());
        $sale->recordPayment($this->pay('400000'));
        $sale->recordPayment($this->pay('300000'));
        $this->assertSame('300000.0000', $sale->outstanding()->toStorage());
        $this->expectCode('PAYMENT_EXCEEDS_OUTSTANDING', fn () => $sale->recordPayment($this->pay('300000.0001')));
        $this->expectCode('PAYMENT_AMOUNT_INVALID', fn () => $sale->recordPayment($this->pay('0')));
        $sale->recordPayment($this->pay('300000'));
        $this->assertSame(S::COMPLETED, $sale->status());
        $this->expectCode('SALE_NOT_PAYABLE', fn () => $sale->recordPayment($this->pay('1')));
        // a completed sale cannot be confirmed again (regression: COMPLETED → CONFIRMED is legal only via payment reversal)
        $this->expectCode('INVALID_STATUS_TRANSITION', fn () => $sale->confirm($this->now(), $this->credit($sale), $this->zero()));
    }

    public function test_outstanding_is_deterministic_under_random_payment_sequences(): void
    {
        mt_srand(4040);
        for ($i = 0; $i < 300; $i++) {
            $sale = $this->sale([$this->line((string) mt_rand(1, 200), (string) mt_rand(20000, 30000))], self::id());
            $sale->confirm($this->now(), $this->credit($sale), $this->zero());
            $grand = $sale->grandTotal();
            $sum = Money::zero();
            $valid = [];
            for ($k = 0; $k < 8 && $sale->status() === S::CONFIRMED; $k++) {
                $out = $sale->outstanding();
                $cents = (int) round(((float) $out->toStorage()) * 100);
                $amount = Money::of(number_format(mt_rand(1, max(1, $cents)) / 100, 2, '.', ''));
                if (mt_rand(0, 4) === 0 && $valid !== []) {
                    $p = array_pop($valid);
                    $sale->reversePayment($p, new PaymentReversal(self::id(), 'x', $this->now(), BusinessDate::fromString('2026-10-03'), null));
                    $sum = $sum->minus($p->amount);
                } elseif (! $amount->isGreaterThan($out)) {
                    $valid[] = $sale->recordPayment($this->pay($amount->toStorage()));
                    $sum = $sum->plus($amount);
                }
                $this->assertTrue($sale->outstanding()->equals($grand->minus($sum)), "case {$i}");
                $this->assertFalse($sale->outstanding()->isNegative());
            }
        }
    }

    public function test_payment_reversal_reopens_and_cannot_repeat(): void
    {
        $sale = $this->sale([$this->line('10', '25000')], self::id());
        [$p] = $sale->confirm($this->now(), $this->credit($sale), $this->zero(), [$this->pay('250000')]);
        $this->assertSame(S::COMPLETED, $sale->status());
        $rev = fn () => new PaymentReversal(self::id(), 'salah input', $this->now(), BusinessDate::fromString('2026-10-03'), null);
        $sale->reversePayment($p, $rev());
        $this->assertSame(S::CONFIRMED, $sale->status());
        $this->assertSame('250000.0000', $sale->outstanding()->toStorage());
        $this->assertTrue($p->isReversed());
        $this->expectCode('PAYMENT_ALREADY_REVERSED', fn () => $sale->reversePayment($p, $rev()));
        $this->expectCode('REVERSAL_REASON_REQUIRED', fn () => new PaymentReversal(self::id(), ' ', $this->now(), BusinessDate::fromString('2026-10-03'), null));
    }

    public function test_walk_in_payment_can_only_be_reversed_with_the_sale(): void
    {
        $sale = $this->sale([$this->line('2', '27000')]);
        $payments = $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('54000')]);
        $rev = fn (Payment $p) => new PaymentReversal(self::id(), 'batal', $this->now(), BusinessDate::fromString('2026-10-03'), null);
        $this->expectCode('WALK_IN_MUST_BE_PAID', fn () => $sale->reversePayment($payments[0], $rev($payments[0])));
        $this->expectCode('SALE_HAS_PAYMENTS', fn () => $sale->reverse($this->now(), 'salah', $payments, false, $rev));
        $sale->reverse($this->now(), 'salah', $payments, true, $rev);
        $this->assertSame(S::REVERSED, $sale->status());
        $this->assertSame('0.0000', $sale->paid()->toStorage());
        $this->assertTrue($payments[0]->isReversed());
        $this->expectCode('INVALID_STATUS_TRANSITION', fn () => $sale->reverse($this->now(), 'lagi', [], true, $rev));
    }

    public function test_returns_value_credit_refund_and_limits(): void
    {
        $line = $this->line('3', '10000.0001');            // total 30 000.0003, not divisible by 3
        $sale = $this->sale([$line], self::id());
        $sale->confirm($this->now(), $this->credit($sale), $this->zero(), [$this->pay('10000')]);
        $req = fn (string $q, ReturnDisposition $d = ReturnDisposition::RETURN_TO_AVAILABLE) => new ReturnLineRequest(self::id(), $line->id, $this->kg($q), $d);
        $ret = fn (array $lines, ?PaymentMethod $m = null) => $sale->recordReturn(self::id(), DocumentNumber::parse('RET-261003-001'), $lines, $m, 'pecah',
            BusinessDate::fromString('2026-10-03'), $this->now(), null);

        $r1 = $ret([$req('1')]);
        $this->assertSame('10000.0001', $r1->totalValue()->toStorage());   // pro-rata, rounded once
        $this->assertSame('10000.0001', $r1->creditAmount->toStorage());   // reduces what is owed
        $this->assertSame('10000.0002', $sale->outstanding()->toStorage());

        $this->expectCode('RETURN_EXCEEDS_SOLD', fn () => $ret([$req('2.001')]));
        $this->expectCode('REFUND_METHOD_REQUIRED', fn () => $ret([$req('2', ReturnDisposition::LOSS)]));

        $r2 = $ret([$req('2', ReturnDisposition::LOSS)], PaymentMethod::CASH);
        $this->assertSame('20000.0002', $r2->totalValue()->toStorage());   // remainder: Σ returned = line total exactly
        $this->assertSame('10000.0002', $r2->creditAmount->toStorage());
        $this->assertSame('10000.0000', $r2->refundAmount->toStorage());   // the cash already paid goes back
        $this->assertSame('30000.0003', $line->returnedAmount()->toStorage());
        $this->assertSame(S::COMPLETED, $sale->status());
        $this->expectCode('RETURN_EXCEEDS_SOLD', fn () => $ret([$req('0.001')]));
        $this->expectCode('SALE_HAS_RETURNS', fn () => $sale->reverse($this->now(), 'x', [], true, fn () => null));
    }

    public function test_returned_values_always_add_up_to_the_line_total(): void
    {
        mt_srand(77);
        for ($i = 0; $i < 300; $i++) {
            $qtyMilli = mt_rand(1000, 90000);
            $line = $this->line(number_format($qtyMilli / 1000, 3, '.', ''), (string) mt_rand(20000, 30000).'.'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT));
            $left = $qtyMilli;
            while ($left > 0) {
                $take = mt_rand(1, $left);
                $line->takeBack($this->kg(number_format($take / 1000, 3, '.', '')));
                $left -= $take;
            }
            $this->assertTrue($line->returnedAmount()->equals($line->total()), "case {$i}");
            $this->assertTrue($line->returnableQuantity()->isZero());
        }
    }

    public function test_cancel_only_while_draft(): void
    {
        $sale = $this->sale();
        $this->expectCode('CANCEL_REASON_REQUIRED', fn () => $sale->cancel(''));
        $sale->cancel('salah input');
        $this->assertSame(S::CANCELLED, $sale->status());
        $this->expectCode('INVALID_STATUS_TRANSITION', fn () => $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero()));
    }

    public function test_fully_discounted_sale_completes_on_confirm(): void
    {
        $sale = $this->sale([$this->line('1', '26000', '26000')]);
        $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero());
        $this->assertSame(S::COMPLETED, $sale->status());
    }

    public function test_rejected_commands_leave_the_sale_untouched(): void
    {
        $sale = $this->sale([$this->line('2', '27000')]);
        $this->expectCode('CREDIT_NOT_ALLOWED', fn () => $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('1000')]));
        $this->assertSame(S::DRAFT, $sale->status());
        $this->assertSame('0.0000', $sale->paid()->toStorage());
        $this->assertNull($sale->dueDate());
        $this->expectCode('PAYMENT_EXCEEDS_OUTSTANDING', fn () => $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('50000'), $this->pay('4001')]));
        $this->assertSame(S::DRAFT, $sale->status());

        $line = $this->line('5', '1000');
        $sale = $this->sale([$line]);
        $sale->confirm($this->now(), CustomerAccount::walkIn(), $this->zero(), [$this->pay('5000')]);
        $this->expectCode('REFUND_METHOD_REQUIRED', fn () => $sale->recordReturn(self::id(), DocumentNumber::parse('RET-261003-001'),
            [new ReturnLineRequest(self::id(), $line->id, $this->kg('1'), ReturnDisposition::LOSS)], null, 'x', BusinessDate::fromString('2026-10-03'), $this->now(), null));
        $this->assertTrue($line->returnedQuantity()->isZero());
        $this->expectCode('RETURN_LINE_DUPLICATE', fn () => $sale->recordReturn(self::id(), DocumentNumber::parse('RET-261003-001'),
            [new ReturnLineRequest(self::id(), $line->id, $this->kg('1'), ReturnDisposition::LOSS), new ReturnLineRequest(self::id(), $line->id, $this->kg('1'), ReturnDisposition::LOSS)],
            PaymentMethod::CASH, 'x', BusinessDate::fromString('2026-10-03'), $this->now(), null));
        $this->assertTrue($line->returnedQuantity()->isZero());
    }

    public function test_invalid_inputs(): void
    {
        $this->expectException(InvariantViolation::class);
        Sale::direct(self::id(), DocumentNumber::parse('SAL-261003-001'), null, self::id(), BusinessDate::fromString('2026-10-03'), []);
    }
}
