<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Domain\Procurement\PurchasedIn;
use App\Domain\Procurement\PurchaseOrderLine;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;

/**
 * Builds PO lines from request input (create and draft edit share it).
 *
 * `uom` is the PURCHASING unit (default: the product's base unit) and
 * `unit_cost` is the agreed price per that unit. The conversion is the
 * product's active conversion at this moment, copied onto the line.
 *
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_cost: string|int}
 */
final class PurchaseOrderLineFactory
{
    public function __construct(
        private readonly MasterDataGuard $guard,
        private readonly IdentityGenerator $ids,
    ) {
    }

    /**
     * @param  list<LineInput>  $lines
     * @return list<PurchaseOrderLine>
     */
    public function build(array $lines): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $product = $this->guard->activeProduct((string) $line['product_id'], "lines.{$i}.product_id");
            $unit = $this->guard->purchaseUnit($product, $line['uom'] ?? null, "lines.{$i}.uom");
            $out[] = PurchaseOrderLine::createPurchased($this->ids->next(), $i + 1, $product->id(), new PurchasedIn(
                $unit,
                Quantity::of((string) $line['quantity'], $unit->unit),
                Money::of((string) $line['unit_cost']),
            ));
        }

        return $out;
    }
}
