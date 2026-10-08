<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;

/**
 * Low-stock threshold of one product at one node (configuration, not stock).
 *
 * - min_quantity is in the product's base UOM; the unit is never stored here,
 *   it is read from the product, and the product's base UOM cannot change
 *   while an active threshold exists (application + DB trigger).
 * - null = monitoring disabled for this product × node (the row is kept so the
 *   history of versions/audit stays intact).
 * - Low stock = AVAILABLE at the node <= min_quantity (evaluated in read models).
 */
final class ReorderPoint extends AggregateRoot
{
    private function __construct(
        Id $id,
        int $version,
        private readonly Id $productId,
        private readonly Id $nodeId,
        /** The product's base UOM at load time — derived, never stored with the threshold. */
        private readonly Uom $uom,
        private ?Quantity $minQuantity,
    ) {
        parent::__construct($id, $version);
    }

    public static function create(Id $id, Id $productId, Id $nodeId, Uom $uom, ?Quantity $minQuantity): self
    {
        self::assertValid($uom, $minQuantity);

        return new self($id, 0, $productId, $nodeId, $uom, $minQuantity);
    }

    public static function reconstitute(Id $id, int $version, Id $productId, Id $nodeId, Uom $uom, ?Quantity $minQuantity): self
    {
        return new self($id, $version, $productId, $nodeId, $uom, $minQuantity);
    }

    /** Returns true when the threshold actually changed. */
    public function set(?Quantity $minQuantity): bool
    {
        self::assertValid($this->uom, $minQuantity);
        $same = $minQuantity === null ? $this->minQuantity === null : ($this->minQuantity !== null && $this->minQuantity->equals($minQuantity));
        if ($same) {
            return false;
        }
        $this->minQuantity = $minQuantity;

        return true;
    }

    public function productId(): Id
    {
        return $this->productId;
    }

    public function nodeId(): Id
    {
        return $this->nodeId;
    }

    public function minQuantity(): ?Quantity
    {
        return $this->minQuantity;
    }

    public function uom(): Uom
    {
        return $this->uom;
    }

    private static function assertValid(Uom $uom, ?Quantity $minQuantity): void
    {
        if ($minQuantity === null) {
            return;
        }
        $minQuantity->assertNonNegative('Reorder point');
        if ($minQuantity->uom->code !== $uom->code) {
            throw InvariantViolation::because('UOM_NOT_BASE', "A reorder point is expressed in the product's base UOM {$uom->code}.", ['field' => 'min_quantity']);
        }
    }

    public function snapshot(): array
    {
        return [
            'product_id' => $this->productId->value,
            'node_id' => $this->nodeId->value,
            'min_quantity' => $this->minQuantity?->toStorage(),
        ];
    }
}
