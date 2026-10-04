<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Quantity\Quantity;

final class CustomerReturnLine
{
    public function __construct(
        public readonly Id $id,
        public readonly Id $saleLineId,
        public readonly Id $productId,
        public readonly Quantity $quantity,
        public readonly Money $value,
        public readonly ReturnDisposition $disposition,
        public readonly ?string $reason,
    ) {
    }
}
