<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Operator roles.
 *
 * MVP (Project A) has exactly two owner/operators, so only OWNER is enabled.
 * The column and the enum exist so that ADMIN, WAREHOUSE, PURCHASING, SALES,
 * DRIVER, FINANCE and MANAGER can be introduced later without a schema
 * redesign. They are intentionally NOT implemented now (no complex RBAC).
 */
enum Role: string
{
    case OWNER = 'OWNER';

    /** All permissions in the MVP are granted to an active OWNER. */
    public function canOperate(): bool
    {
        return match ($this) {
            self::OWNER => true,
        };
    }
}
