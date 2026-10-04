<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * One immutable line of the inventory ledger.
 *
 * - quantity is a strictly positive magnitude in the product's base UOM;
 *   direction says whether stock goes in or out of the position.
 * - the movement type must allow the direction (RECEIPT is always IN …).
 * - REVERSAL movements point at the movement they compensate, and only
 *   REVERSAL movements may do so.
 * - correlationId groups the movements of one business event (e.g. the
 *   REGRADE_OUT and REGRADE_IN of one regrade, or all movements of a receipt).
 * - lotId (A5-B) is the batch/lot dimension. Every movement written since
 *   A5-B names its lot; historical rows have none (they belong to the LEGACY
 *   lot of their product/node).
 *
 * There is no way to change a movement after it is created.
 */
final class StockMovement
{
    private function __construct(
        public readonly Id $id,
        public readonly MovementType $type,
        public readonly StockKey $key,
        public readonly Direction $direction,
        public readonly Quantity $quantity,
        public readonly BusinessDate $businessDate,
        public readonly DateTimeImmutable $occurredAt,
        public readonly SourceDocument $source,
        public readonly Id $correlationId,
        public readonly ?string $actorUserId,
        public readonly ?string $reason,
        public readonly ?Money $unitCost,
        public readonly ?Id $reversesMovementId,
        public readonly ?Id $lotId = null,
    ) {
    }

    public static function record(
        Id $id,
        MovementType $type,
        StockKey $key,
        Direction $direction,
        Quantity $quantity,
        BusinessDate $businessDate,
        DateTimeImmutable $occurredAt,
        SourceDocument $source,
        Id $correlationId,
        ?string $actorUserId,
        ?string $reason = null,
        ?Money $unitCost = null,
        ?Id $reversesMovementId = null,
        ?Id $lotId = null,
    ): self {
        $quantity->assertPositive('Movement quantity');
        if (! $type->allows($direction)) {
            throw InvariantViolation::because('MOVEMENT_DIRECTION_INVALID', "{$type->value} cannot move stock {$direction->name}.");
        }
        if (($type === MovementType::REVERSAL) !== ($reversesMovementId !== null)) {
            throw InvariantViolation::because('MOVEMENT_REVERSAL_LINK_INVALID', 'Only REVERSAL movements reference a reversed movement, and they must.');
        }
        $unitCost?->assertNonNegative('Unit cost');
        $reason = $reason === null ? null : (trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500));

        return new self($id, $type, $key, $direction, $quantity, $businessDate, $occurredAt, $source, $correlationId, $actorUserId, $reason, $unitCost, $reversesMovementId, $lotId);
    }

    /** Rebuild from storage (no validation beyond the types; the row was validated on insert). */
    public static function reconstitute(
        Id $id, MovementType $type, StockKey $key, Direction $direction, Quantity $quantity, BusinessDate $businessDate,
        DateTimeImmutable $occurredAt, SourceDocument $source, Id $correlationId, ?string $actorUserId, ?string $reason,
        ?Money $unitCost, ?Id $reversesMovementId, ?Id $lotId = null,
    ): self {
        return new self($id, $type, $key, $direction, $quantity, $businessDate, $occurredAt, $source, $correlationId, $actorUserId, $reason, $unitCost, $reversesMovementId, $lotId);
    }

    /**
     * The compensating movement: same position, lot and quantity, opposite
     * direction, type REVERSAL, linked to this movement. A historical movement
     * without lot is reversed into its LEGACY lot ($legacyLotId).
     */
    public function reverse(Id $id, BusinessDate $businessDate, DateTimeImmutable $occurredAt, SourceDocument $source, Id $correlationId, ?string $actorUserId, string $reason, ?Id $legacyLotId = null): self
    {
        if ($this->type === MovementType::REVERSAL) {
            throw InvariantViolation::because('REVERSAL_OF_REVERSAL', 'A reversal cannot itself be reversed; post a new transaction instead.');
        }

        return self::record($id, MovementType::REVERSAL, $this->key, $this->direction->opposite(), $this->quantity, $businessDate, $occurredAt, $source, $correlationId, $actorUserId, $reason, $this->unitCost, $this->id, $this->lotId ?? $legacyLotId);
    }

    /** Signed change to the position: +quantity for IN, −quantity for OUT. */
    public function signedQuantity(): Quantity
    {
        return $this->direction === Direction::IN ? $this->quantity : $this->quantity->negated();
    }
}
