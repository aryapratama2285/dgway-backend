<?php

declare(strict_types=1);

namespace App\Application\Shared\MasterData;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Quantity\Uom;

/**
 * Checks master data before a NEW transaction uses it (A3 §33, A4 §34):
 * supplier / customer role active, product active, node active with the right capability,
 * quantity in the product's base UOM.
 *
 * Reading historical documents never goes through this guard, so a later
 * deactivation does not make old documents unreadable.
 *
 * UOM POLICY (A3 §34): the ledger is always in the product's base UOM. A
 * quantity sent in another unit is REJECTED (UOM_NOT_BASE) — there is no
 * implicit conversion. Product-specific conversions are a future extension.
 */
final class MasterDataGuard
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly NodeRepository $nodes,
        private readonly ProductRepository $products,
    ) {
    }

    public function activeSupplier(string $partnerId): BusinessPartner
    {
        $partner = $this->partners->get(Id::fromString($partnerId));
        if (! $partner->isActiveSupplier()) {
            throw InvariantViolation::because('SUPPLIER_NOT_USABLE', "{$partner->code()->value} is not an active supplier.", ['field' => 'supplier_id']);
        }

        return $partner;
    }

    public function activeCustomer(string $partnerId): BusinessPartner
    {
        $partner = $this->partners->get(Id::fromString($partnerId));
        if (! $partner->isActiveCustomer()) {
            throw InvariantViolation::because('CUSTOMER_NOT_USABLE', "{$partner->code()->value} is not an active customer.", ['field' => 'customer_id']);
        }

        return $partner;
    }

    public function nodeWith(string $nodeId, NodeCapability ...$capabilities): Node
    {
        $node = $this->nodes->get(Id::fromString($nodeId));
        foreach ($capabilities as $capability) {
            if (! $node->can($capability)) {
                throw InvariantViolation::because(
                    'NODE_NOT_USABLE',
                    "Node {$node->code()->value} is inactive or lacks capability {$capability->value}.",
                    ['field' => 'node_id', 'capability' => $capability->value],
                );
            }
        }

        return $node;
    }

    public function activeProduct(string $productId, string $field = 'product_id'): Product
    {
        $product = $this->products->get(Id::fromString($productId));
        if (! $product->isActive()) {
            throw InvariantViolation::because('PRODUCT_INACTIVE', "Product {$product->sku()->value} is inactive.", ['field' => $field]);
        }

        return $product;
    }

    /** Parse a quantity in the product's base UOM; reject any other unit. */
    public static function baseQuantity(Product $product, string|int $value, ?string $uom = null): Quantity
    {
        self::assertBaseUom($product, $uom);

        return Quantity::of($value, $product->baseUom());
    }

    public static function assertBaseUom(Product $product, ?string $uom): Uom
    {
        if ($uom !== null && strtoupper(trim($uom)) !== $product->baseUom()->code) {
            throw InvariantViolation::because(
                'UOM_NOT_BASE',
                "{$product->sku()->value} is stocked in {$product->baseUom()->code}; '{$uom}' would need a conversion, which is not defined.",
                ['field' => 'uom', 'base_uom' => $product->baseUom()->code],
            );
        }

        return $product->baseUom();
    }
}
