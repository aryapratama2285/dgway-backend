<?php

declare(strict_types=1);

namespace App\Application\Procurement;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Procurement\GoodsReceipt;
use App\Domain\Procurement\GoodsReceiptRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/** Abandon a DRAFT receipt (no stock effect). Drafts are cancelled, not deleted. */
final class CancelGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $receiptId, string $reason, ?int $expectedVersion = null): GoodsReceipt
    {
        return $this->tx->run(function () use ($actor, $receiptId, $reason, $expectedVersion): GoodsReceipt {
            $gr = $this->receipts->getForUpdate(Id::fromString($receiptId));
            ExpectedVersion::check('Goods receipt', $gr, $expectedVersion);
            $before = $gr->snapshot();
            $gr->cancel($reason);
            $this->receipts->save($gr, $actor->userId);
            $this->audit->changed($actor, 'goods_receipt.cancelled', 'goods_receipt', $gr, $before);

            return $gr;
        });
    }
}
