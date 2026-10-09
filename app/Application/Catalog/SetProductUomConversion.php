<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\ProductUomConversion;
use App\Domain\Catalog\ProductUomConversionRepository;
use App\Domain\Catalog\UnitOfMeasureRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/**
 * F4A: define or change "1 [unit] = factor [base unit]" for a product (upsert
 * by product × unit). Changing or deactivating a conversion affects NEW
 * documents only: PO and receipt lines carry their own snapshot.
 *
 * The product row is locked first, so a concurrent base-UOM change cannot
 * interleave (the base unit is also locked by trigger while a conversion is
 * active). Needs `catalog.manage_uom_conversion`; versioned and audited.
 */
final class SetProductUomConversion
{
    public const AUDIT_ACTION = 'catalog.uom_conversion_set';

    public function __construct(
        private readonly ProductUomConversionRepository $conversions,
        private readonly ProductRepository $products,
        private readonly UnitOfMeasureRepository $uoms,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $productId, string $uomCode, string $factor, bool $active = true, ?int $expectedVersion = null): ProductUomConversion
    {
        $this->authorizer->authorize($actor, Permission::MANAGE_UOM_CONVERSION);

        return $this->tx->run(function () use ($actor, $productId, $uomCode, $factor, $active, $expectedVersion): ProductUomConversion {
            $product = $this->products->getForUpdate(Id::fromString($productId));
            $unit = $this->uoms->findActive($uomCode)
                ?? throw InvariantViolation::because('UOM_UNKNOWN', "Unit of measure '{$uomCode}' does not exist or is inactive.", ['field' => 'uom']);

            $conversion = $this->conversions->findForUpdate($product->id(), $unit->code, $product->baseUom());
            if ($conversion === null) {
                $conversion = ProductUomConversion::create($this->ids->next(), $product->id(), $unit, $product->baseUom(), $factor);
                if (! $active) {
                    $conversion->set($factor, false);
                }
                $this->conversions->add($conversion, $actor->userId);
                $this->audit->created($actor, self::AUDIT_ACTION, 'product_uom_conversion', $conversion, ['sku' => $product->sku()->value]);

                return $conversion;
            }

            ExpectedVersion::check('ProductUomConversion', $conversion, $expectedVersion);
            $before = $conversion->snapshot();
            if ($conversion->set($factor, $active)) {
                $this->conversions->save($conversion, $actor->userId);
                $this->audit->changed($actor, self::AUDIT_ACTION, 'product_uom_conversion', $conversion, $before, ['sku' => $product->sku()->value]);
            }

            return $conversion;
        });
    }
}
