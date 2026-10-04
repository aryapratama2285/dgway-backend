<?php

declare(strict_types=1);

namespace App\Application\Shared\Security;

/**
 * Sensitive actions that need an explicit permission on top of "an active,
 * authenticated operator". Routine operations need no entry here; they are
 * open to every active operator (MVP: OWNER only).
 *
 * Extension point (A5-B §19): future roles (ADMIN, CASHIER, WAREHOUSE,
 * PURCHASING, DELIVERY) are granted permissions in config/dgway.php
 * `authorization.role_permissions`; use cases ask the Authorizer, never the
 * role directly, so adding a role is configuration + a users_role_check
 * widening, not a code change in every use case.
 */
enum Permission: string
{
    case OVERRIDE_CREDIT_LIMIT = 'sales.override_credit_limit';

    /** A5-C: change the business identity printed on documents (not printer hardware). */
    case MANAGE_PRINT_SETTINGS = 'settings.manage_print';
}
