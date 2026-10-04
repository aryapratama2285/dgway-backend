<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Sales\PaymentRequest;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use DateTimeImmutable;

/**
 * Turns API input into PaymentRequests with a PAY document number.
 * Numbers come from the A1 generator inside the caller's transaction, so a
 * rolled-back payment burns no number.
 *
 * @phpstan-type PaymentInput array{method: string, amount: string|int, reference?: ?string, notes?: ?string, paid_at?: ?string, business_date?: ?string}
 */
final class PaymentRequestFactory
{
    public const PREFIX = 'PAY';

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
    ) {
    }

    /** @param PaymentInput $in */
    public function make(Actor $actor, array $in, Currency $currency, string $field = ''): PaymentRequest
    {
        $method = PaymentMethod::tryFrom(strtoupper(trim((string) ($in['method'] ?? ''))))
            ?? throw InvariantViolation::because('PAYMENT_METHOD_INVALID', "Unknown payment method '".($in['method'] ?? '')."'. Use CASH, BANK_TRANSFER or QRIS.", ['field' => $field.'method']);
        $date = empty($in['business_date']) ? $this->clock->today() : BusinessDate::fromString((string) $in['business_date']);
        $paidAt = empty($in['paid_at']) ? $this->clock->now() : new DateTimeImmutable((string) $in['paid_at']);

        return new PaymentRequest(
            $this->ids->next(), $this->numbers->next(self::PREFIX, $date), $method, Money::of((string) $in['amount'], $currency),
            $paidAt, $date, $actor->userId, $in['reference'] ?? null, $in['notes'] ?? null,
        );
    }
}
