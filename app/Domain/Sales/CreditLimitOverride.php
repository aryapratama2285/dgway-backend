<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Shared\Exception\InvariantViolation;

/**
 * An explicit, reasoned decision by an accountable operator to let ONE sale
 * exceed the customer's credit limit. It never changes the limit itself and
 * is not a flag: without this object the limit is enforced, with it the
 * sale must actually need it (otherwise CREDIT_OVERRIDE_NOT_REQUIRED).
 * Whether the operator may grant it is decided by the application layer
 * (Authorizer, Permission::OVERRIDE_CREDIT_LIMIT) before the domain sees it.
 */
final class CreditLimitOverride
{
    public const MIN_REASON = 5;

    public const MAX_REASON = 500;

    private function __construct(public readonly string $reason, public readonly string $approvedBy)
    {
    }

    public static function approved(string $reason, string $approvedByUserId): self
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON || mb_strlen($reason) > self::MAX_REASON) {
            throw InvariantViolation::because('CREDIT_OVERRIDE_REASON_REQUIRED',
                'A credit limit override needs a reason of '.self::MIN_REASON.'–'.self::MAX_REASON.' characters.', ['field' => 'reason']);
        }

        return new self($reason, $approvedByUserId);
    }
}
