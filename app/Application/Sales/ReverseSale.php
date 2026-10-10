<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\InventoryPoster;
use App\Application\Inventory\LedgerReversal;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Sales\Payment;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\PaymentReversal;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * Compensates a final sale: REVERSAL movements put the stock back (traceable
 * to the original SALE movements), the original sale stays readable with
 * status REVERSED. Payment policy: see Sale::reverse() — with
 * reverse_payments=true every valid payment gets a reversal record (refund)
 * in the same transaction; otherwise a sale with payments is refused.
 */
final class ReverseSale
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly PaymentRepository $payments,
        private readonly LedgerReversal $ledger,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $saleId, string $reason, bool $reversePayments = false, ?int $expectedVersion = null): Sale
    {
        $this->authorizer->authorize($actor, Permission::REVERSE_SALE);

        return $this->tx->run(function () use ($actor, $saleId, $reason, $reversePayments, $expectedVersion): Sale {
            $sale = $this->sales->getForUpdate(Id::fromString($saleId));
            ExpectedVersion::check('Sale', $sale, $expectedVersion);
            $before = $sale->snapshot();
            $valid = $this->payments->validPaymentsOfSale($sale->id());
            $now = $this->clock->now();

            $sale->reverse($now, $reason, $valid, $reversePayments,
                fn (Payment $p) => new PaymentReversal($this->ids->next(), $reason, $now, $this->clock->today(), $actor->userId));

            $movements = $this->ledger->reverseSource('sale', $sale->id(), $actor->userId, $reason);
            foreach ($valid as $payment) {
                $this->payments->addReversal($payment);
            }
            $this->sales->save($sale, $actor->userId);

            $context = ['sale' => $sale->number->toString(), 'reason' => $sale->statusReason()];
            $this->audit->changed($actor, 'sale.reversed', 'sale', $sale, $before, $context);
            foreach ($valid as $payment) {
                $this->recorder->record(new AuditEntry($actor, 'payment.reversed', 'payment', $payment->id()->value, null, $payment->snapshot(), $context));
            }
            $this->recorder->record(new AuditEntry($actor, 'inventory.reversal', 'sale', $sale->id()->value, null, ['movements' => InventoryPoster::summarize($movements)], $context));

            return $sale;
        });
    }
}
