<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\PaymentReversal;
use App\Domain\Sales\SaleRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * Cancels a wrongly recorded payment by appending a reversal record. The
 * payment row itself is never changed. Locks the sale first (same lock as
 * every money change), then re-reads the payment, so two simultaneous
 * reversals of one payment cannot both pass (and the database has a UNIQUE
 * reversal per payment as backstop).
 */
final class ReversePayment
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly PaymentRepository $payments,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $paymentId, string $reason): SaleCommandResult
    {
        $this->authorizer->authorize($actor, Permission::REVERSE_PAYMENT);

        return $this->tx->run(function () use ($actor, $paymentId, $reason): SaleCommandResult {
            $saleId = $this->payments->get(Id::fromString($paymentId))->saleId;
            $sale = $this->sales->getForUpdate($saleId);
            $payment = $this->payments->get(Id::fromString($paymentId)); // fresh, under the sale lock
            $before = $payment->snapshot();

            $sale->reversePayment($payment, new PaymentReversal($this->ids->next(), $reason, $this->clock->now(), $this->clock->today(), $actor->userId));
            $this->payments->addReversal($payment);
            $this->sales->save($sale, $actor->userId);

            $this->audit->changed($actor, 'payment.reversed', 'payment', $payment, $before,
                ['sale' => $sale->number->toString(), 'reason' => $payment->reversal()?->reason, 'outstanding_after' => $sale->outstanding()->toStorage()]);

            return new SaleCommandResult($sale, $payment);
        });
    }
}
