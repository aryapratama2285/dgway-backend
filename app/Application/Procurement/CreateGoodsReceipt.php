<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\MasterData\MasterDataGuard;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\NodeCapability;
use App\Domain\Procurement\Disposition;
use App\Domain\Procurement\DispositionType;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptLine;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Time\BusinessDate;
use App\Domain\Shared\Time\Clock;
use DateTimeImmutable;

/**
 * Records what physically arrived and what is decided for each part of it,
 * as a DRAFT receipt. Nothing touches inventory until PostGoodsReceipt.
 *
 * Works for both paths:
 *   - PO-based: purchase_order_id + purchase_order_line_id per line;
 *     unit cost defaults to the PO line's agreed cost;
 *   - direct purchase: no PO; unit cost is required per line.
 *
 * @phpstan-type DispositionInput array{type: string, quantity: string|int, target_product_id?: ?string, reason?: ?string}
 * @phpstan-type LineInput array{product_id: string, quantity: string|int, uom?: ?string, unit_cost?: string|int|null,
 *                               purchase_order_line_id?: ?string, dispositions: list<DispositionInput>}
 */
final class CreateGoodsReceipt
{
    public const PREFIX = 'GR';

    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly MasterDataGuard $guard,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IdentityGenerator $ids,
        private readonly Clock $clock,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param list<LineInput> $lines */
    public function execute(
        Actor $actor,
        string $supplierId,
        string $nodeId,
        array $lines,
        ?string $purchaseOrderId = null,
        ?string $businessDate = null,
        ?string $receivedAt = null,
        ?string $notes = null,
    ): GoodsReceipt {
        return $this->tx->run(function () use ($actor, $supplierId, $nodeId, $lines, $purchaseOrderId, $businessDate, $receivedAt, $notes): GoodsReceipt {
            $supplier = $this->guard->activeSupplier($supplierId);
            $node = $this->guard->nodeWith($nodeId, NodeCapability::RECEIVING, NodeCapability::INVENTORY);
            $po = $purchaseOrderId === null ? null : $this->orders->get(Id::fromString($purchaseOrderId));
            $date = $businessDate === null ? $this->clock->today() : BusinessDate::fromString($businessDate);
            $at = $receivedAt === null ? $this->clock->now() : new DateTimeImmutable($receivedAt);

            $grLines = [];
            foreach (array_values($lines) as $i => $in) {
                $product = $this->guard->activeProduct((string) $in['product_id'], "lines.{$i}.product_id");
                $received = MasterDataGuard::baseQuantity($product, (string) $in['quantity'], $in['uom'] ?? null);
                $poLineId = isset($in['purchase_order_line_id']) ? Id::fromString((string) $in['purchase_order_line_id']) : null;

                $unitCost = match (true) {
                    isset($in['unit_cost']) => Money::of((string) $in['unit_cost']),
                    $po !== null && $poLineId !== null => $po->line($poLineId)->unitCost,
                    default => throw InvariantViolation::because('UNIT_COST_REQUIRED', "Line ".($i + 1).': a direct purchase needs the unit cost.', ['field' => "lines.{$i}.unit_cost"]),
                };

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
                    $dispositions[] = Disposition::of($type, MasterDataGuard::baseQuantity($product, (string) $d['quantity']), $target, $d['reason'] ?? null);
                }

                $grLines[] = GoodsReceiptLine::create($this->ids->next(), $i + 1, $product->id(), $received, $unitCost, $dispositions, $poLineId,
                    empty($in['manufactured_date']) ? null : BusinessDate::fromString((string) $in['manufactured_date']),
                    empty($in['best_before_date']) ? null : BusinessDate::fromString((string) $in['best_before_date']),
                    // A5-C: only what the operator recorded from the supplier; never defaulted from the receipt date
                    empty($in['collection_date']) ? null : BusinessDate::fromString((string) $in['collection_date']),
                    isset($in['supplier_reference']) ? (string) $in['supplier_reference'] : null);
            }

            $gr = GoodsReceipt::draft($this->ids->next(), $this->numbers->next(self::PREFIX, $date), $supplier->id(), $node->id(), $date, $at, $grLines, $po, $notes);
            $this->receipts->add($gr, $actor->userId);
            $this->audit->created($actor, 'goods_receipt.created', 'goods_receipt', $gr);

            return $gr;
        });
    }
}
