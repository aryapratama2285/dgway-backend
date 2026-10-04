<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Sales\Sale;
use App\Domain\Sales\SaleRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/** Cancels a DRAFT sale (nothing happened yet: no stock, no money). */
final class CancelSale
{
    public function __construct(
        private readonly SaleRepository $sales,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $saleId, string $reason, ?int $expectedVersion = null): Sale
    {
        return $this->tx->run(function () use ($actor, $saleId, $reason, $expectedVersion): Sale {
            $sale = $this->sales->getForUpdate(Id::fromString($saleId));
            ExpectedVersion::check('Sale', $sale, $expectedVersion);
            $before = $sale->snapshot();
            $sale->cancel($reason);
            $this->sales->save($sale, $actor->userId);
            $this->audit->changed($actor, 'sale.cancelled', 'sale', $sale, $before);

            return $sale;
        });
    }
}
