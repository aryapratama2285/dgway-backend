<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Inventory\Direction;
use App\Domain\Inventory\InventoryLot;
use App\Domain\Inventory\InventoryOperation;
use App\Domain\Inventory\InventoryOperationType;
use App\Domain\Inventory\LotRepository;
use App\Domain\Inventory\SourceDocument;
use App\Domain\Inventory\InventoryOperationRepository;
use App\Domain\Inventory\StockBucket;
use App\Domain\Network\NodeCapability;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Time\Clock;

/**
 * Use cases for stock operations outside receiving. Each has its own
 * intention-revealing method (recordLoss, regrade, recover,
 * shipSupplierReturn, adjust); they share numbering, persistence, posting
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
    ) {
    }

    public function recordLoss(Actor $actor, string $nodeId, string $productId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        return $this->run($actor, $nodeId, $productId, function ($id, $no, $node, $product, $date, $at) use ($quantity, $reason, $uom) {
            return InventoryOperation::loss($id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at);
        });
    }

    public function regrade(Actor $actor, string $nodeId, string $fromProductId, string $toProductId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        return $this->run($actor, $nodeId, $fromProductId, function ($id, $no, $node, $product, $date, $at) use ($toProductId, $quantity, $reason, $uom) {
            $target = $this->guard->activeProduct($toProductId, 'to_product_id');
            MasterDataGuard::assertBaseUom($target, $product->baseUom()->code);

            return InventoryOperation::regrade($id, $no, $node->id(), $product->id(), $target->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at);
        });
    }

    public function recover(Actor $actor, string $nodeId, string $productId, string $quantity, string $fromBucket, string $reason, ?string $uom = null): InventoryOperation
    {
        $bucket = StockBucket::tryFrom(strtoupper($fromBucket))
            ?? throw InvariantViolation::because('BUCKET_INVALID', "Unknown stock bucket '{$fromBucket}'.", ['field' => 'from_bucket']);

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::recovery(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $bucket, $reason, $date, $at,
        ));
    }

    public function shipSupplierReturn(Actor $actor, string $nodeId, string $productId, string $quantity, string $reason, ?string $uom = null): InventoryOperation
    {
        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::supplierReturn(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $reason, $date, $at,
        ));
    }

    public function adjust(Actor $actor, string $nodeId, string $productId, string $quantity, string $direction, string $reason, ?string $uom = null): InventoryOperation
    {
        $dir = match (strtoupper($direction)) {
            'IN' => Direction::IN,
            'OUT' => Direction::OUT,
            default => throw InvariantViolation::because('DIRECTION_INVALID', 'direction must be IN or OUT.', ['field' => 'direction']),
        };

        return $this->run($actor, $nodeId, $productId, fn ($id, $no, $node, $product, $date, $at) => InventoryOperation::adjustment(
            $id, $no, $node->id(), $product->id(), MasterDataGuard::baseQuantity($product, $quantity, $uom), $dir, $reason, $date, $at,
        ));
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
                ['number' => $op->number->toString(), 'reason' => $op->reason, 'movements' => InventoryPoster::summarize($movements)],
            ));

            return $op;
        });
    }
}
