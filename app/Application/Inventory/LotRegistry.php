<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Domain\Inventory\InventoryLot;
use App\Domain\Inventory\LotRepository;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Time\BusinessDate;

/**
 * Issues lot numbers (A1 DocumentNumberGenerator, prefix LOT →
 * LOT-YYMMDD-NNN, per business date, rolled back with the transaction) and
 * registers lots created by business events. There is no API that creates a
 * lot directly: receipts, regrades and stock-count adjustments do.
 */
final class LotRegistry
{
    public const PREFIX = 'LOT';

    public function __construct(
        private readonly LotRepository $lots,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditTrail $audit,
    ) {
    }

    public function nextNumber(BusinessDate $date): DocumentNumber
    {
        return $this->numbers->next(self::PREFIX, $date);
    }

    public function register(InventoryLot $lot, Actor $actor): InventoryLot
    {
        $this->lots->add($lot, $actor->userId);
        $this->audit->created($actor, 'inventory_lot.created', 'inventory_lot', $lot, ['lot' => $lot->number->toString()]);

        return $lot;
    }
}
