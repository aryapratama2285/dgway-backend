<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Inventory\ReorderPoint;
use App\Domain\Inventory\ReorderPointRepository;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/**
 * F3: set or clear the low-stock threshold of a product at a node.
 *
 * - [$minQuantity] is a decimal in the product's BASE UOM (product precision
 *   enforced, no conversion) or null = monitoring disabled.
 * - The product row is locked first, so a concurrent base-UOM change
 *   (UpdateProduct, which also locks it) cannot interleave: the threshold and
 *   the unit it is expressed in always agree.
 * - Setting a threshold needs an active product and a node that holds stock
 *   (INVENTORY capability); clearing works for any existing product/node.
 * - Versioned (expected_version → 409 CONCURRENT_MODIFICATION) and audited.
 */
final class SetReorderPoint
{
    public const AUDIT_ACTION = 'inventory.reorder_point_set';

    public function __construct(
        private readonly ReorderPointRepository $points,
        private readonly ProductRepository $products,
        private readonly NodeRepository $nodes,
        private readonly MasterDataGuard $guard,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $productId, string $nodeId, ?string $minQuantity, ?int $expectedVersion = null): ReorderPoint
    {
        return $this->tx->run(function () use ($actor, $productId, $nodeId, $minQuantity, $expectedVersion): ReorderPoint {
            $product = $this->products->getForUpdate(Id::fromString($productId));
            if ($minQuantity !== null) {
                $this->guard->activeProduct($productId);
                $node = $this->guard->nodeWith($nodeId, NodeCapability::INVENTORY);
                $min = MasterDataGuard::baseQuantity($product, $minQuantity);
                $min->assertNonNegative('Reorder point');
            } else {
                $node = $this->nodes->get(Id::fromString($nodeId));
                $min = null;
            }

            $point = $this->points->findForUpdate($product->id(), $node->id(), $product->baseUom());
            if ($point === null) {
                $point = ReorderPoint::create($this->ids->next(), $product->id(), $node->id(), $product->baseUom(), $min);
                $this->points->add($point, $actor->userId);
                $this->audit->created($actor, self::AUDIT_ACTION, 'reorder_point', $point, ['uom' => $product->baseUom()->code]);

                return $point;
            }

            ExpectedVersion::check('ReorderPoint', $point, $expectedVersion);
            $before = $point->snapshot();
            if ($point->set($min)) {
                $this->points->save($point, $actor->userId);
                $this->audit->changed($actor, self::AUDIT_ACTION, 'reorder_point', $point, $before, ['uom' => $product->baseUom()->code]);
            }

            return $point;
        });
    }
}
