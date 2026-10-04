<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Quantity\Quantity;

/** A part of a received quantity and what is decided for it. */
final class Disposition
{
    private function __construct(
        public readonly DispositionType $type,
        public readonly Quantity $quantity,
        public readonly ?Id $targetProductId,
        public readonly ?string $reason,
    ) {
    }

    public static function of(DispositionType $type, Quantity $quantity, ?Id $targetProductId = null, ?string $reason = null): self
    {
        $quantity->assertPositive("{$type->value} quantity");
        if (! $type->appliesAtReceipt()) {
            throw InvariantViolation::because('DISPOSITION_NOT_FOR_RECEIPT', 'RECOVERY applies to stock that is already non-sellable; use the inventory recovery operation.');
        }
        if ($type->requiresTargetProduct() !== ($targetProductId !== null)) {
            throw InvariantViolation::because(
                'DISPOSITION_TARGET_INVALID',
                $type->requiresTargetProduct() ? 'REGRADE needs the target product.' : "{$type->value} must not have a target product.",
            );
        }
        $reason = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500);

        return new self($type, $quantity, $targetProductId, $reason);
    }
}
