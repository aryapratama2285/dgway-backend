<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Domain\Procurement\Disposition;
use App\Domain\Procurement\DispositionType;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\PurchasedIn;
use App\Domain\Procurement\PurchaseOrder;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;
use App\Domain\Shared\Time\BusinessDate;

/**
 * Builds goods-receipt lines from request input (create and draft edit share it).
 *
 * Unit (F4A): `uom` is the unit the goods were counted in (default: base unit).
 * Against a PO line bought in that same unit, the PO line's conversion SNAPSHOT
 * is used (the agreed conversion, even if the master changed since); otherwise
 * the product's active conversion. Dispositions are in the same unit as the line.
 *
 * Cost: `unit_cost` = the ACTUAL cost per that unit. Default against a PO line:
 * the agreed PO price when the unit is the PO line's unit, the PO line's base
 * unit cost when receiving in the base unit. A direct purchase needs it.
 *
 * @phpstan-type DispositionInput array{type: string, quantity: string|int, target_product_id?: ?string, reason?: ?string}
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_cost?: string|int|null,
 *                               purchase_order_line_id?: ?string, dispositions: list<DispositionInput>}
 */
final class ReceiptLineFactory
{
    public function __construct(
        private readonly MasterDataGuard $guard,
        private readonly IdentityGenerator $ids,
    ) {
    }

    /**
     * @param  list<LineInput>  $lines
     * @return list<GoodsReceiptLine>
     */
    public function build(array $lines, ?PurchaseOrder $po): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $in) {
            $product = $this->guard->activeProduct((string) $in['product_id'], "lines.{$i}.product_id");
            $poLineId = isset($in['purchase_order_line_id']) ? Id::fromString((string) $in['purchase_order_line_id']) : null;
            $poLine = $po !== null && $poLineId !== null ? $po->line($poLineId) : null;

            $uom = isset($in['uom']) && trim((string) $in['uom']) !== '' ? strtoupper(trim((string) $in['uom'])) : null;
            $agreed = $poLine?->purchasedIn->conversion;
            $unit = $agreed !== null && $uom !== null && $uom === $agreed->unit->code && $poLine->productId->equals($product->id())
                ? $agreed
                : $this->guard->purchaseUnit($product, $uom, "lines.{$i}.uom");

            $unitCost = match (true) {
                isset($in['unit_cost']) => Money::of((string) $in['unit_cost']),
                $poLine !== null && $unit->equals($poLine->purchasedIn->conversion) => $poLine->purchasedIn->unitPrice,
                $poLine !== null && $unit->isIdentity() => $poLine->unitCost,
                default => throw InvariantViolation::because('UNIT_COST_REQUIRED', 'Line '.($i + 1).': '
                    .($poLine === null ? 'a direct purchase needs the unit cost.' : 'receiving in another unit than the PO line needs the unit cost.'),
                    ['field' => "lines.{$i}.unit_cost"]),
            };
            $purchased = new PurchasedIn($unit, Quantity::of((string) $in['quantity'], $unit->unit), $unitCost);

            $dispositions = [];
            foreach ($in['dispositions'] ?? [] as $j => $d) {
                $type = DispositionType::tryFrom(strtoupper((string) ($d['type'] ?? '')))
                    ?? throw InvariantViolation::because('DISPOSITION_TYPE_INVALID', "Unknown disposition type '".($d['type'] ?? '')."'.", ['field' => "lines.{$i}.dispositions.{$j}.type"]);
                $target = null;
                if (! empty($d['target_product_id'])) {
                    $targetProduct = $this->guard->activeProduct((string) $d['target_product_id'], "lines.{$i}.dispositions.{$j}.target_product_id");
                    // Regrade conserves quantity in the same unit; no conversion exists.
                    MasterDataGuard::assertBaseUom($targetProduct, $product->baseUom()->code);
                    $target = $targetProduct->id();
                }
                $quantity = $unit->toBase(Quantity::of((string) $d['quantity'], $unit->unit), "lines.{$i}.dispositions.{$j}.quantity");
                $dispositions[] = Disposition::of($type, $quantity, $target, $d['reason'] ?? null);
            }

            $out[] = GoodsReceiptLine::create($this->ids->next(), $i + 1, $product->id(), $purchased->baseQuantity(), $purchased->baseUnitCost(), $dispositions, $poLineId,
                empty($in['manufactured_date']) ? null : BusinessDate::fromString((string) $in['manufactured_date']),
                empty($in['best_before_date']) ? null : BusinessDate::fromString((string) $in['best_before_date']),
                // A5-C: only what the operator recorded from the supplier; never defaulted from the receipt date
                empty($in['collection_date']) ? null : BusinessDate::fromString((string) $in['collection_date']),
                isset($in['supplier_reference']) ? (string) $in['supplier_reference'] : null,
                $purchased);
        }

        return $out;
    }
}
