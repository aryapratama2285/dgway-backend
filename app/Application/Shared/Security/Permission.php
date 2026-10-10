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
    /** F3: correct stock through an ADJUSTMENT (delta or counted mode). Not needed for consumption or loss. */
    case ADJUST_STOCK = 'inventory.adjust';

    /** F4A: finish a partially/fully received purchase order (CLOSED, terminal). */
    case CLOSE_PURCHASE_ORDER = 'procurement.close';
    /** F4A: cancel a purchase order that received nothing. */
    case CANCEL_PURCHASE_ORDER = 'procurement.cancel';
    /** F4A: reverse a POSTED goods receipt (compensating ledger movements). */
    case REVERSE_GOODS_RECEIPT = 'procurement.reverse';
    /** F4A: define or change a product's purchasing-unit conversions. */
    case MANAGE_UOM_CONVERSION = 'catalog.manage_uom_conversion';

    /** F4B: reverse a confirmed/completed sale (stock back through REVERSAL movements, payments reversed explicitly). */
    case REVERSE_SALE = 'sales.reverse';
    /** F4B: reverse one payment (the amount becomes owed again). */
    case REVERSE_PAYMENT = 'payments.reverse';
    /** F4B: record a customer return (stock back, credit/refund). */
    case RECORD_CUSTOMER_RETURN = 'sales.return';
}
