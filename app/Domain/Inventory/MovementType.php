<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Every reason stock can change. Declared in full now so A4+ (sales,
 * transfers, customer returns) add behaviour, not schema redesign.
 *
 * Implemented in A3: RECEIPT, LOSS, SUPPLIER_RETURN, REGRADE_OUT, REGRADE_IN,
 * RECOVERY, ADJUSTMENT_IN, ADJUSTMENT_OUT, REVERSAL.
 * Reserved for later phases: SALE, CUSTOMER_RETURN, TRANSFER_OUT, TRANSFER_IN.
 */
enum MovementType: string
{
    case RECEIPT = 'RECEIPT';
    case SALE = 'SALE';
    case CUSTOMER_RETURN = 'CUSTOMER_RETURN';
    case SUPPLIER_RETURN = 'SUPPLIER_RETURN';
    case TRANSFER_OUT = 'TRANSFER_OUT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case LOSS = 'LOSS';
    case RECOVERY = 'RECOVERY';
    case REGRADE_OUT = 'REGRADE_OUT';
    case REGRADE_IN = 'REGRADE_IN';
    case ADJUSTMENT_IN = 'ADJUSTMENT_IN';
    case ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';
    case REVERSAL = 'REVERSAL';

    /** @return list<Direction> */
    public function allowedDirections(): array
    {
        return match ($this) {
            self::RECEIPT, self::CUSTOMER_RETURN, self::TRANSFER_IN, self::REGRADE_IN, self::ADJUSTMENT_IN => [Direction::IN],
            self::SALE, self::SUPPLIER_RETURN, self::TRANSFER_OUT, self::LOSS, self::REGRADE_OUT, self::ADJUSTMENT_OUT => [Direction::OUT],
            // RECOVERY moves stock between buckets (out of RETURN_PENDING/QUARANTINE, into AVAILABLE).
            // REVERSAL mirrors the movement it reverses.
            self::RECOVERY, self::REVERSAL => [Direction::IN, Direction::OUT],
        };
    }

    public function allows(Direction $direction): bool
    {
        return in_array($direction, $this->allowedDirections(), true);
    }
}
