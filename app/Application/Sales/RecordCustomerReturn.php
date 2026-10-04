<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Inventory\InventoryPoster;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Network\NodeCapability;
use App\Domain\Sales\PaymentMethod;
use App\Domain\Sales\ReturnDisposition;
use App\Domain\Sales\ReturnLineRequest;
use App\Domain\Sales\CustomerReturnRepository;
use App\Domain\Sales\SaleRepository;
use App\Domain\Sales\SaleStatus;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\Clock;

/**
 * Goods come back from a customer, against the original sale lines:
 *   - never more than sold − already returned (checked under the sale lock);
 *   - stock re-enters through CUSTOMER_RETURN movements into the bucket the
 *     operator chose (never automatically AVAILABLE; LOSS is written off);
 *   - the value first reduces what is owed, the rest is recorded as refund.
 *
 * @phpstan-type ReturnLineInput array{sale_line_id: string, quantity: string|int, uom?: ?string, disposition: string, reason?: ?string}
 */
final class RecordCustomerReturn
{
    public const PREFIX = 'RET';

    public function __construct(
        private readonly SaleRepository $sales,
        private readonly CustomerReturnRepository $returns,
        private readonly ProductRepository $products,
        private readonly MasterDataGuard $guard,
        private readonly SalesMovementPlanner $planner,
        private readonly InventoryPoster $inventory,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly AuditRecorder $recorder,
    ) {
    }

    /** @param list<ReturnLineInput> $lines */
    public function execute(Actor $actor, string $saleId, array $lines, string $reason, ?string $refundMethod = null, ?int $expectedVersion = null): SaleCommandResult
    {
        return $this->tx->run(function () use ($actor, $saleId, $lines, $reason, $refundMethod, $expectedVersion): SaleCommandResult {
            $sale = $this->sales->getForUpdate(Id::fromString($saleId));
            ExpectedVersion::check('Sale', $sale, $expectedVersion);
            $this->guard->nodeWith($sale->nodeId->value, NodeCapability::INVENTORY);
            $wasCompleted = $sale->status() === SaleStatus::COMPLETED;

            $requests = [];
            foreach (array_values($lines) as $i => $in) {
                $saleLine = $sale->line(Id::fromString((string) $in['sale_line_id']));
                // historical product: may be inactive by now, the return is still about that sale
                $product = $this->products->get($saleLine->productId);
                $disposition = ReturnDisposition::tryFrom(strtoupper((string) ($in['disposition'] ?? '')))
                    ?? throw InvariantViolation::because('RETURN_DISPOSITION_INVALID', 'Disposition must be RETURN_TO_AVAILABLE, RETURN_TO_QUARANTINE or LOSS.', ['field' => "lines.{$i}.disposition"]);
                $requests[] = new ReturnLineRequest($this->ids->next(), $saleLine->id,
                    MasterDataGuard::baseQuantity($product, (string) $in['quantity'], $in['uom'] ?? null), $disposition, $in['reason'] ?? null);
            }
            $method = $refundMethod === null ? null : (PaymentMethod::tryFrom(strtoupper($refundMethod))
                ?? throw InvariantViolation::because('PAYMENT_METHOD_INVALID', "Unknown refund method '{$refundMethod}'.", ['field' => 'refund_method']));

            $date = $this->clock->today();
            $return = $sale->recordReturn($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $requests, $method, $reason, $date, $this->clock->now(), $actor->userId);

            $movements = $this->planner->forReturn($return);
            $this->inventory->post($movements);
            $this->returns->add($return);
            $this->sales->save($sale, $actor->userId);

            $context = ['sale' => $sale->number->toString(), 'customer_return' => $return->number->toString()];
            $this->audit->created($actor, 'customer_return.recorded', 'customer_return', $return, $context);
            $this->recorder->record(new AuditEntry($actor, 'inventory.customer_return', 'customer_return', $return->id()->value, null, ['movements' => InventoryPoster::summarize($movements)], $context));
            if (! $wasCompleted && $sale->status() === SaleStatus::COMPLETED) {
                $this->recorder->record(new AuditEntry($actor, 'sale.completed', 'sale', $sale->id()->value, null, ['credited' => $sale->credited()->toStorage()], $context));
            }

            return new SaleCommandResult($sale, $return);
        });
    }
}
