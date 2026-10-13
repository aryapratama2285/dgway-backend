<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Inventory\AdjustmentReason;
use App\Domain\Inventory\ConsumptionReason;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\InventoryLot;
use App\Domain\Inventory\InventoryOperation;
use App\Domain\Inventory\InventoryOperationType;
use App\Domain\Inventory\LotRepository;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\InventoryOperationRepository;
use App\Domain\Inventory\StockBucket;
use App\Domain\Inventory\StockKey;
use App\Domain\Inventory\StockLedger;
use App\Domain\Network\NodeCapability;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Exception\StateConflict;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\Clock;

/**
 * Use cases for stock operations outside receiving. Each has its own
 * intention-revealing method (recordLoss, regrade, recover,
 * shipSupplierReturn, adjust, adjustToCount, consume); they share numbering, persistence, posting
 * and audit so every operation behaves the same way:
 *
 *   document (INV-…) → movements (one correlation id) → balances → audit,
 *   in one transaction, with the no-negative-stock rule enforced under lock.
 */
final class RecordInventoryOperation
{
    public const PREFIX = 'INV';

    public function __construct(
        private readonly InventoryOperationRepository $operations,
        private readonly InventoryPoster $inventory,
        private readonly MasterDataGuard $guard,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditRecorder $audit,
        private readonly LotAllocator $allocator,
        private readonly LotRegistry $registry,
        private readonly LotRepository $lots,
        private readonly Authorizer $authorizer,
        private readonly StockLedger $ledger,
    ) {
    }

    public function recordLoss(Actor $actor, string $nodeId, string $productId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::CORRECT_STOCK);

        return $this->run($actor, $nodeId, $productId, function ($id, $no, $node, $product, $date, $at) use ($quantity, $reason, $uom) {
            return InventoryOperation::loss($id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at);
        });
    }

    public function regrade(Actor $actor, string $nodeId, string $fromProductId, string $toProductId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::CORRECT_STOCK);

        return $this->run($actor, $nodeId, $fromProductId, function ($id, $no, $node, $product, $date, $at) use ($toProductId, $quantity, $reason, $uom) {
            $target = $this->guard->activeProduct($toProductId, 'to_product_id');
            MasterDataGuard::assertBaseUom($target, $product->baseUom()->code);

            return InventoryOperation::regrade($id, $no, $node->id(), $product->id(), $target->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at);
        });
    }

    public function recover(Actor $actor, string $nodeId, string $productId, string $quantity, string $fromBucket, string $reason, ?string $uom = null): InventoryOperation
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::CORRECT_STOCK);
        $bucket = StockBucket::tryFrom(strtoupper($fromBucket))
            ?? throw InvariantViolation::because('BUCKET_INVALID', "Unknown stock bucket '{$fromBucket}'.", ['field' => 'from_bucket']);

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::recovery(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $bucket, $reason, $date, $at,
        ));
    }

    public function shipSupplierReturn(Actor $actor, string $nodeId, string $productId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::CORRECT_STOCK);

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::supplierReturn(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at,
        ));
    }

    /**
     * Delta mode (A3 contract, unchanged): IN or OUT by [$quantity]. F3: needs the
     * `inventory.adjust` permission; [$reasonCode] is optional.
     */
    public function adjust(Actor $actor, string $nodeId, string $productId, string $quantity, string $direction, string $reason, ?string $uom = null, ?string $reasonCode = null): InventoryOperation
    {
        $this->authorizer->authorize($actor, Permission::ADJUST_STOCK);
        $dir = match (strtoupper($direction)) {
            'IN' => Direction::IN,
            'OUT' => Direction::OUT,
            default => throw InvariantViolation::because('DIRECTION_INVALID', 'direction must be IN or OUT.', ['field' => 'direction']),
        };
        $code = self::adjustmentReason($reasonCode);

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::adjustment(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $dir, $reason, $date, $at, $code,
        ));
    }

    /**
     * F3 counted mode (stock opname): the operator states what is physically on
     * the shelf; the server locks the AVAILABLE position INSIDE the transaction,
     * reads what the system holds and posts the difference as an adjustment.
     *
     * - [$expectedAvailable] (optional) = the system figure the operator saw; if
     *   the stock moved since, 409 STOCK_CHANGED_SINCE_COUNT and nothing is written;
     * - counted = system → 422 ADJUSTMENT_NOT_REQUIRED;
     * - counted > system → ADJUSTMENT IN (new ADJUSTMENT lot, cost basis unknown);
     * - counted < system → ADJUSTMENT OUT (lots consumed FIFO).
     * Counted and system quantities are stored on the operation.
     */
    public function adjustToCount(Actor $actor, string $nodeId, string $productId, string $countedQuantity, ?string $expectedAvailable, string $reason, ?string $uom = null, ?string $reasonCode = null): InventoryOperation
    {
        $this->authorizer->authorize($actor, Permission::ADJUST_STOCK);
        $code = self::adjustmentReason($reasonCode);

        return $this->run($actor, $nodeId, $productId, function ($id, $no, $node, $product, $date, $at) use ($countedQuantity, $expectedAvailable, $reason, $uom, $code) {
            $counted = MasterDataGuard::baseQuantity($product, $countedQuantity, $uom);
            $counted->assertNonNegative('Counted quantity');
            $key = new StockKey($product->id(), $node->id(), StockBucket::AVAILABLE);
            $system = $this->ledger->lockBalances([$key], [$product->id()->value => $product->baseUom()])[$key->toString()]->quantity();
            if ($expectedAvailable !== null) {
                $expected = Quantity::of($expectedAvailable, $product->baseUom());
                if (! $expected->equals($system)) {
                    throw StateConflict::because('STOCK_CHANGED_SINCE_COUNT', 'The stock changed since it was counted: reload and count again.', [
                        'expected_available' => $expected->toStorage(),
                        'current_available' => $system->toStorage(),
                    ]);
                }
            }
            $delta = $counted->minus($system);
            if ($delta->isZero()) {
                throw InvariantViolation::because('ADJUSTMENT_NOT_REQUIRED', 'The counted quantity equals the system quantity; nothing to adjust.', [
                    'counted_quantity' => $counted->toStorage(),
                    'system_quantity' => $system->toStorage(),
                ]);
            }
            $direction = $delta->isPositive() ? Direction::IN : Direction::OUT;

            return InventoryOperation::adjustment($id, $no, $node->id(), $product->id(), $direction === Direction::IN ? $delta : $delta->negated(),
                $direction, $reason, $date, $at, $code, $counted, $system);
        });
    }

    /**
     * F3: DG Way consumed its own material. Same authorization as other routine
     * stock operations (an active operator); the product's classification is
     * context, not a permission. Lots are consumed FIFO under lock; the quantity
     * cannot exceed AVAILABLE (409 INSUFFICIENT_STOCK).
     */
    public function consume(Actor $actor, string $nodeId, string $productId, string $quantity, string $reasonCode, ?string $notes = null, ?string $referenceText = null, ?string $uom = null): InventoryOperation
    {
        $code = ConsumptionReason::tryFrom(strtoupper(trim($reasonCode)))
            ?? throw InvariantViolation::because('REASON_CODE_INVALID', "Unknown consumption reason '{$reasonCode}'.", ['field' => 'reason_code']);

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::internalConsumption(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $code, $notes, $referenceText, $date, $at,
        ));
    }

    private static function adjustmentReason(?string $reasonCode): ?AdjustmentReason
    {
        if ($reasonCode === null || trim($reasonCode) === '') {
            return null;
        }

        return AdjustmentReason::tryFrom(strtoupper(trim($reasonCode)))
            ?? throw InvariantViolation::because('REASON_CODE_INVALID', "Unknown adjustment reason '{$reasonCode}'.", ['field' => 'reason_code']);
    }

    /** @param callable(...): InventoryOperation $build */
    private function run(Actor $actor, string $nodeId, string $productId, callable $build): InventoryOperation
    {
        return $this->tx->run(function () use ($actor, $nodeId, $productId, $build): InventoryOperation {
            $node = $this->guard->nodeWith($nodeId, NodeCapability::INVENTORY);
            $product = $this->guard->activeProduct($productId);
            $date = $this->clock->today();

            $op = $build($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $node, $product, $date, $this->clock->now());
            $this->operations->add($op, $actor->userId);

            // Lots: consume existing lots FIFO under lock, or create the lot a surplus needs.
            $portions = [];
            $derived = [];
            $newLot = null;
            if ($op->consumesLots()) {
                [$portions] = $this->allocator->allocate([[$op->sourcePosition(), $op->quantity]]);
                if ($op->type === InventoryOperationType::REGRADE) {
                    foreach ($portions as $p) {
                        $lot = InventoryLot::derivedFrom($this->lots->get($p->lotId), $this->ids->next(), $this->registry->nextNumber($date), $op->targetProductId,
                            $p->quantity, new SourceDocument('inventory_operation', $op->id), $date, $op->occurredAt);
                        $derived[$p->lotId->value] = $this->registry->register($lot, $actor)->id();
                    }
                }
            } else {
                $newLot = $this->registry->register(InventoryLot::fromAdjustment($this->ids->next(), $this->registry->nextNumber($date), $op->productId,
                    $op->nodeId, $op->quantity, $op->id, $date, $op->occurredAt), $actor)->id();
            }

            $movements = $op->movements(fn () => $this->ids->next(), $actor->userId, $portions, $derived, $newLot);
            $this->inventory->post($movements);
            $this->audit->record(new AuditEntry(
                $actor,
                $op->type->auditAction(),
                'inventory_operation',
                $op->id->value,
                null,
                array_filter([
                    'number' => $op->number->toString(),
                    'reason' => $op->reason,
                    'reason_code' => $op->reasonCode,
                    'reference_text' => $op->referenceText,
                    'counted_quantity' => $op->countedQuantity?->toStorage(),
                    'system_quantity' => $op->systemQuantity?->toStorage(),
                    'movements' => InventoryPoster::summarize($movements),
                ], fn ($v) => $v !== null),
            ));

            return $op;
        });
    }
}
