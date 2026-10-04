<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\InventoryPoster;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Sales\CreditLimitOverride;
use App\Domain\Sales\CustomerAccount;
use App\Domain\Sales\PaymentRepository;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleRepository;
use App\Domain\Sales\SaleStatus;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\Clock;

/**
 * Makes a sale final, in ONE transaction:
 *   1. lock the sale row (a second confirm waits, then fails with
 *      INVALID_STATUS_TRANSITION → stock leaves once);
 *   2. re-check master data (customer, node, products still usable);
 *   3. if part of the total will be owed: lock the customer's credit row,
 *      then read the exposure (concurrency-safe credit limit);
 *   4. confirm + counter payments (domain decides credit / limit);
 *   5. SALE movements through the A3 ledger (no negative stock, row locks);
 *   6. persist sale + payments, audit.
 * Lock order everywhere in Sales: sale → customer credit row → stock rows.
 *
 * A credit-limit override (A5-B §18) enters only through
 * ConfirmSaleWithCreditOverride; it is re-authorized HERE, inside the
 * transaction, so no caller can pass an override around the permission
 * check, and it is audited as sale.credit_limit_overridden.
 */
final class ConfirmSale
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly PaymentRepository $payments,
        private readonly PaymentRequestFactory $paymentRequests,
        private readonly CustomerCreditGate $credit,
        private readonly MasterDataGuard $guard,
        private readonly SalesMovementPlanner $planner,
        private readonly InventoryPoster $inventory,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
        private readonly Authorizer $authorizer,
    ) {
    }

    /** @param list<array<string, mixed>> $payments */
    public function execute(Actor $actor, string $saleId, array $payments = [], ?int $expectedVersion = null, ?CreditLimitOverride $override = null): Sale
    {
        return $this->tx->run(function () use ($actor, $saleId, $payments, $expectedVersion, $override): Sale {
            if ($override !== null) {
                $this->authorizer->authorize($actor, Permission::OVERRIDE_CREDIT_LIMIT);
            }
            $sale = $this->sales->getForUpdate(Id::fromString($saleId));
            ExpectedVersion::check('Sale', $sale, $expectedVersion);
            $before = $sale->snapshot();

            $this->guard->nodeWith($sale->nodeId->value, NodeCapability::INVENTORY, NodeCapability::FULFILLMENT);
            foreach ($sale->lines() as $line) {
                $this->guard->activeProduct($line->productId->value);
            }

            $requests = [];
            foreach (array_values($payments) as $i => $p) {
                $requests[] = $this->paymentRequests->make($actor, $p, $sale->currency, "payments.{$i}.");
            }
            $paidNow = array_reduce($requests, fn (Money $s, $r) => $s->plus($r->amount), Money::zero($sale->currency));

            $exposure = Money::zero($sale->currency);
            if ($sale->customerId === null) {
                $account = CustomerAccount::walkIn();
            } else {
                $needsCredit = $sale->grandTotal()->isGreaterThan($paidNow);
                if ($needsCredit) {
                    $this->credit->lock($sale->customerId); // before reading terms and exposure
                }
                $customer = $this->guard->activeCustomer($sale->customerId->value);
                $terms = $customer->customerRole()->terms();
                $account = CustomerAccount::customer($customer->id(), $terms->termDays, $terms->creditLimit);
                if ($needsCredit) {
                    $exposure = $this->credit->outstandingOf($sale->customerId);
                }
            }

            $recorded = $sale->confirm($this->clock->now(), $account, $exposure, $requests, $override);

            $movements = $this->planner->forSale($sale, $actor->userId);
            $this->inventory->post($movements);
            $this->sales->save($sale, $actor->userId);
            foreach ($recorded as $payment) {
                $this->payments->add($payment);
            }

            $context = ['sale' => $sale->number->toString()];
            $this->audit->changed($actor, 'sale.confirmed', 'sale', $sale, $before);
            if ($override !== null) {
                $this->recorder->record(new AuditEntry($actor, 'sale.credit_limit_overridden', 'sale', $sale->id()->value, null, [
                    'sale_id' => $sale->id()->value,
                    'sale_number' => $sale->number->toString(),
                    'customer_id' => $sale->customerId?->value,
                    'approved_by' => $override->approvedBy,
                    'reason' => $override->reason,
                    'credit_limit' => $account->creditLimit?->toStorage(),
                    'exposure_before' => $exposure->toStorage(),
                    'requested_credit' => $sale->outstanding()->toStorage(),
                    'exposure_after' => $exposure->plus($sale->outstanding())->toStorage(),
                    'overridden_at' => $this->clock->now()->format(DATE_ATOM),
                ], $context));
            }
            $this->recorder->record(new AuditEntry($actor, 'inventory.sale', 'sale', $sale->id()->value, null, ['movements' => InventoryPoster::summarize($movements)], $context));
            foreach ($recorded as $payment) {
                $this->audit->created($actor, 'payment.recorded', 'payment', $payment, $context);
            }
            if ($sale->status() === SaleStatus::COMPLETED) {
                $this->recorder->record(new AuditEntry($actor, 'sale.completed', 'sale', $sale->id()->value, null, ['paid' => $sale->paid()->toStorage()], $context));
            }

            return $sale;
        });
    }
}
