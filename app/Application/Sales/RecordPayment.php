<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\SaleRepository;
use App\Domain\Sales\SaleStatus;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/**
 * Records money received for a sale (partial or final). The sale row is
 * locked, so two simultaneous payments are applied one after the other and
 * the second sees the reduced outstanding → no overpayment.
 */
final class RecordPayment
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly PaymentRepository $payments,
        private readonly PaymentRequestFactory $requests,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
    ) {
    }

    /** @param array<string, mixed> $input method, amount, reference?, notes?, paid_at?, business_date? */
    public function execute(Actor $actor, string $saleId, array $input, ?int $expectedVersion = null): SaleCommandResult
    {
        return $this->tx->run(function () use ($actor, $saleId, $input, $expectedVersion): SaleCommandResult {
            $sale = $this->sales->getForUpdate(Id::fromString($saleId));
            ExpectedVersion::check('Sale', $sale, $expectedVersion);
            $payment = $sale->recordPayment($this->requests->make($actor, $input, $sale->currency));
            $this->payments->add($payment);
            $this->sales->save($sale, $actor->userId);

            $context = ['sale' => $sale->number->toString(), 'outstanding_after' => $sale->outstanding()->toStorage()];
            $this->audit->created($actor, 'payment.recorded', 'payment', $payment, $context);
            if ($sale->status() === SaleStatus::COMPLETED) {
                $this->recorder->record(new AuditEntry($actor, 'sale.completed', 'sale', $sale->id()->value, null, ['paid' => $sale->paid()->toStorage()], $context));
            }

            return new SaleCommandResult($sale, $payment);
        });
    }
}
