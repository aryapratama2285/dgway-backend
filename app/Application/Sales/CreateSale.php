<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleLine;
use App\Domain\Sales\SaleRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;

/**
 * Creates a DIRECT sale (no customer order needed). customer_id null =
 * anonymous walk-in. With $confirm = true the sale is confirmed in the SAME
 * transaction (counter flow: one tap → sale + stock out + payment).
 *
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_price: string|int, discount?: string|int|null}
 */
final class CreateSale
{
    public const PREFIX = 'SAL';

    public function __construct(
        private readonly SaleRepository $sales,
        private readonly ConfirmSale $confirmSale,
        private readonly MasterDataGuard $guard,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param  list<LineInput>  $lines
     * @param  list<array<string, mixed>>  $payments  only with $confirm
     */
    public function execute(Actor $actor, ?string $customerId, string $nodeId, array $lines, ?string $businessDate = null, ?string $notes = null,
        bool $confirm = false, array $payments = []): Sale
    {
        return $this->tx->run(function () use ($actor, $customerId, $nodeId, $lines, $businessDate, $notes, $confirm, $payments): Sale {
            $customer = $customerId === null ? null : $this->guard->activeCustomer($customerId);
            $node = $this->guard->nodeWith($nodeId, NodeCapability::INVENTORY, NodeCapability::FULFILLMENT);
            $date = $businessDate === null ? $this->clock->today() : BusinessDate::fromString($businessDate);

            $saleLines = [];
            foreach (array_values($lines) as $i => $in) {
                $product = $this->guard->activeProduct((string) $in['product_id'], "lines.{$i}.product_id");
                $saleLines[] = SaleLine::create(
                    $this->ids->next(), $i + 1, $product->id(),
                    MasterDataGuard::baseQuantity($product, (string) $in['quantity'], $in['uom'] ?? null),
                    Money::of((string) $in['unit_price']),
                    isset($in['discount']) ? Money::of((string) $in['discount']) : null,
                );
            }

            $sale = Sale::direct($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $customer?->id(), $node->id(), $date, $saleLines, $notes);
            $this->sales->add($sale, $actor->userId);
            $this->audit->created($actor, 'sale.created', 'sale', $sale);

            return $confirm ? $this->confirmSale->execute($actor, $sale->id()->value, $payments) : $sale;
        });
    }
}
