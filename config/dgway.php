<?php

return [

    /*
    | Business time zone. Timestamps are stored in UTC; business_date columns
    | and daily reports use this zone.
    */
    'business_timezone' => env('DGWAY_BUSINESS_TIMEZONE', 'Asia/Jakarta'),

    /*
    | Lifetime of API bearer tokens issued to operator devices, in days.
    | null = no expiry (not recommended).
    */
    'token_ttl_days' => (int) env('DGWAY_TOKEN_TTL_DAYS', 30),

    /*
    | Minimum operator password length.
    */
    'password_min_length' => 10,

    /*
    | Identifier of the default inventory node (decision D12). Generic on
    | purpose: it does not encode where the node physically is.
    */
    'default_node_code' => 'DG-WAY-PRIMARY',

    /*
    | Transactional tables that reference products (table => column).
    | Used to lock a product's base UOM once it has been transacted.
    | A test asserts every foreign key to products.id outside master data is
    | listed here, so a new transaction table cannot be forgotten.
    */
    'product_reference_tables' => [
        'purchase_order_lines.product_id',
        'goods_receipt_lines.product_id',
        'goods_receipt_dispositions.target_product_id',
        'stock_movements.product_id',
        'stock_balances.product_id',
        'inventory_operations.product_id',
        'inventory_operations.target_product_id',
        'sale_lines.product_id',
        'customer_return_lines.product_id',
        'inventory_lots.product_id',
        'stock_lot_balances.product_id',
    ],

    /*
    | F3 — configuration tables that reference products but are NOT transactions
    | (a threshold is not a use of the product). They do not lock the product's
    | classification; the base UOM is guarded separately while an active reorder
    | point exists (UpdateProduct + DB trigger). Every product foreign key must be
    | listed in exactly one of the two registries (ProjectionAndRegistryTest).
    */
    'product_configuration_tables' => [
        'reorder_points.product_id',
        // F4A: purchasing-unit conversions (documents keep their own snapshot)
        'product_uom_conversions.product_id',
    ],

    /*
    | How long committed idempotency keys are kept before pruning (days).
    | Must exceed the longest time a client may retry the same action.
    */
    'idempotency_retention_days' => (int) env('DGWAY_IDEMPOTENCY_RETENTION_DAYS', 30),

    /*
    | A5-B §17 — operational defaults served to the mobile app by GET /config,
    | so Flutter never hard-codes a node UUID, currency or page size.
    | The node and walk-in customer are resolved by CODE at request time
    | (created by `php artisan dgway:bootstrap`).
    */
    'currency' => 'IDR',
    'walk_in_customer_code' => env('DGWAY_WALK_IN_CUSTOMER_CODE', 'WALK-IN'),
    'default_page_size' => 25,

    /*
    | A5-B §18–19 — authorization extension point. Use cases ask the
    | Authorizer for a Permission (App\Application\Shared\Security\Permission);
    | this map decides which role holds which permission ('*' = all).
    | Only OWNER exists today (users_role_check). The other roles are listed
    | so the extension point is visible; they hold nothing and cannot be
    | assigned until the role CHECK is widened in a later phase.
    */
    'authorization' => [
        'role_permissions' => [
            'OWNER' => ['*'],
            'ADMIN' => [],
            'CASHIER' => [],
            'WAREHOUSE' => [],
            'PURCHASING' => [],
            'DELIVERY' => [],
        ],
    ],

    /*
    | A5-C — default business identity printed on receipts, documents and
    | labels until the owner saves print settings (PATCH /print/settings).
    | No personal data. Receipt width 80 mm primary, 58 mm compatible (D9).
    | Printer hardware (Bluetooth address, pairing, ESC/POS) is NOT configured
    | in the backend: it belongs to the device / Flutter app.
    */
    'print' => [
        'business_name' => env('DGWAY_PRINT_BUSINESS_NAME', 'DG WAY'),
        'business_subtitle' => env('DGWAY_PRINT_BUSINESS_SUBTITLE', 'Supply & Distribution'),
        'receipt_width_mm' => (int) env('DGWAY_PRINT_RECEIPT_WIDTH_MM', 80),
    ],
];
