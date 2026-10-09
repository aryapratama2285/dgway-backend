<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Procurement\PurchaseOrderRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Time\BusinessDate;
use DateTimeImmutable;

/**
 * F4A: edit a DRAFT goods receipt — business_date, received_at, notes and/or
 * lines (`lines` REPLACES all lines). Supplier, node and purchase order define
 * the receipt and do not change: cancel the draft and create another one.
 * POSTED (or later) receipts are refused with 409 INVALID_STATUS_TRANSITION.
 * Nothing touches inventory until the receipt is posted.
 */
final class UpdateGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly ReceiptLineFactory $lineFactory,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $id, Patch $patch, ?int $expectedVersion = null): GoodsReceipt
    {
        return $this->tx->run(function () use ($actor, $id, $patch, $expectedVersion): GoodsReceipt {
            $gr = $this->receipts->getForUpdate(Id::fromString($id));
            ExpectedVersion::check('Goods receipt', $gr, $expectedVersion);
            $before = $gr->snapshot();
            $po = $gr->purchaseOrderId === null ? null : $this->orders->get($gr->purchaseOrderId);

            $revised = GoodsReceipt::revisedDraft(
                $gr,
                $patch->has('business_date') ? BusinessDate::fromString((string) $patch->get('business_date')) : $gr->businessDate,
                $patch->has('received_at') && $patch->get('received_at') !== null ? new DateTimeImmutable((string) $patch->get('received_at')) : $gr->receivedAt,
                $patch->has('lines') ? $this->lineFactory->build(array_values($patch->array('lines') ?? []), $po) : $gr->lines(),
                $po,
                $patch->has('notes') ? $patch->string('notes') : $gr->notes(),
            );
            $this->receipts->saveDraft($revised, $patch->has('lines'));
            $this->audit->changed($actor, 'goods_receipt.updated', 'goods_receipt', $revised, $before);

            return $revised;
        });
    }
}
