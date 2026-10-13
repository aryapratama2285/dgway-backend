<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Operator roles.
 *
 * - OWNER: every permission ('*'), including the sensitive ones.
 * - STAFF (F5A, decision D1): routine operational work only — selling,
 *   receiving payments, purchasing, receiving goods, internal consumption,
 *   master data without credit terms. It holds none of the sensitive
 *   permissions (reversals, returns, stock correction, credit, configuration).
 *
 * What a role may do is configuration (config/dgway.php
 * `authorization.role_permissions`), asked through the Authorizer; use cases
 * never test the role directly. Operator administration has no API: it is
 * done on the server console (dgway:operator:*).
 */
enum Role: string
{
    case OWNER = 'OWNER';
    case STAFF = 'STAFF';

    /** Both roles may sign in and perform routine (non-permission-guarded) work when active. */
    public function canOperate(): bool
    {
        return match ($this) {
            self::OWNER, self::STAFF => true,
        };
    }
}
