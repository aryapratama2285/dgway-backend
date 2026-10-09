<?php

/*
|--------------------------------------------------------------------------
| DG Way Core Transactional Data — Generation 1 · data contract
|--------------------------------------------------------------------------
|
| One entry per PostgreSQL table. This is the machine-readable part of
| docs/DATA_EVOLUTION_STRATEGY.md and the input of:
|   - tests/Feature/DataEvolution/DataContractTest.php (every table must be
|     declared; declared guarantees must really exist in the database);
|   - php artisan dgway:data:fingerprint (migration reconciliation);
|   - php artisan dgway:schema:catalog (docs/SCHEMA_CATALOG.md).
|
| context      bounded context that OWNS the table (single source of truth)
| aggregate    aggregate / document the rows belong to
| kind         master      versioned, editable, never deleted, identity guarded
|              document    status machine; immutable once final
|              line        child rows of a document, immutable once final
|              append_only ledger / financial history / audit: INSERT only
|              projection  derived, rebuildable from a ledger
|              counter     sequence state that must survive a migration
|              operational short-lived technical state
|              framework   Laravel infrastructure, not business data
| migration    preserve    must be carried into any future system unchanged
|              carry_over  state to carry over (not history), e.g. counters
|              rebuild     recompute from preserved tables
|              discard     may be dropped/recreated by a new platform
| business_key human/business identifier besides the UUID (if any)
| fingerprint  semantic columns hashed by dgway:data:fingerprint, in order.
|              Generated/derived columns are excluded (checked as invariants).
| fingerprint_additions  ['a5c' => [...]] columns added by later phases, hashed
|              per phase as "<table>+<phase>" (keeps every earlier checksum comparable).
| fingerprint_added  columns added to an existing table by a later phase
|              (e.g. A5-B lot_id). Hashed as a separate checksum "<table>+added"
|              so the original checksum stays comparable with older
|              fingerprints (normalized comparison, see DATA_EVOLUTION_STRATEGY §7).
*/

return [
    'tables' => [
        // ── Shared configuration (A5-C) ──────────────────────────────────
        'print_settings' => ['context' => 'Shared', 'aggregate' => 'PrintSettings', 'kind' => 'master', 'migration' => 'preserve',
            'fingerprint' => ['id', 'business_name', 'business_subtitle', 'address', 'phone', 'footer_text', 'receipt_width_mm', 'created_at']],

        // ── Identity ─────────────────────────────────────────────────────
        'users' => ['context' => 'Identity', 'aggregate' => 'Operator', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'email',
            'fingerprint' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']],
        'personal_access_tokens' => ['context' => 'Identity', 'aggregate' => 'AccessToken', 'kind' => 'operational', 'migration' => 'discard'],

        // ── Catalog ──────────────────────────────────────────────────────
        // F4A: per-product purchasing units; documents keep a snapshot, so a change never rewrites history
        'product_uom_conversions' => ['context' => 'Catalog', 'aggregate' => 'ProductUomConversion', 'kind' => 'master', 'migration' => 'preserve',
            'business_key' => 'product_id+uom_code',
            'fingerprint' => ['id', 'product_id', 'uom_code', 'factor_to_base', 'is_active', 'created_at']],
        'uoms' => ['context' => 'Catalog', 'aggregate' => 'UnitOfMeasure', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'code',
            'fingerprint' => ['id', 'code', 'name', 'precision', 'is_active']],
        'product_categories' => ['context' => 'Catalog', 'aggregate' => 'ProductCategory', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'code',
            'fingerprint' => ['id', 'code', 'name', 'parent_id', 'is_active', 'created_at']],
        'products' => ['context' => 'Catalog', 'aggregate' => 'Product', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'sku',
            'fingerprint' => ['id', 'sku', 'name', 'category_id', 'base_uom_code', 'description', 'is_active', 'created_at']],

        // ── Partner ──────────────────────────────────────────────────────
        'business_partners' => ['context' => 'Partner', 'aggregate' => 'BusinessPartner', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'code',
            'fingerprint' => ['id', 'code', 'name', 'legal_name', 'party_type', 'phone', 'email', 'address_street', 'address_village', 'address_district',
                'address_city', 'address_province', 'address_postal_code', 'address_landmark', 'address_latitude', 'address_longitude', 'notes', 'is_active', 'created_at']],
        'business_partner_roles' => ['context' => 'Partner', 'aggregate' => 'BusinessPartner', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'partner_id+role',
            'fingerprint' => ['id', 'partner_id', 'role', 'is_active', 'payment_term_days', 'credit_limit', 'customer_type', 'notes', 'created_at']],

        // ── Network ──────────────────────────────────────────────────────
        'locations' => ['context' => 'Network', 'aggregate' => 'Location', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'code',
            'fingerprint' => ['id', 'code', 'name', 'address_street', 'address_village', 'address_district', 'address_city', 'address_province',
                'address_postal_code', 'address_landmark', 'address_latitude', 'address_longitude', 'notes', 'is_active', 'created_at']],
        'nodes' => ['context' => 'Network', 'aggregate' => 'Node', 'kind' => 'master', 'migration' => 'preserve', 'business_key' => 'code',
            'fingerprint' => ['id', 'code', 'name', 'location_id', 'capabilities', 'notes', 'is_active', 'created_at']],

        // ── Procurement ──────────────────────────────────────────────────
        'purchase_orders' => ['context' => 'Procurement', 'aggregate' => 'PurchaseOrder', 'kind' => 'document', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'supplier_id', 'node_id', 'business_date', 'currency', 'status', 'cancel_reason', 'created_by', 'created_at'],
            'fingerprint_additions' => ['f4a' => ['confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'closed_at', 'closed_by', 'close_reason_code', 'close_note']]],
        'purchase_order_lines' => ['context' => 'Procurement', 'aggregate' => 'PurchaseOrder', 'kind' => 'line', 'migration' => 'preserve',
            'fingerprint' => ['id', 'purchase_order_id', 'line_no', 'product_id', 'uom_code', 'ordered_quantity', 'received_quantity', 'unit_cost', 'line_total'],
            'fingerprint_additions' => ['f4a' => ['purchase_uom_code', 'purchase_quantity', 'purchase_unit_price', 'conversion_factor']]],
        'goods_receipts' => ['context' => 'Procurement', 'aggregate' => 'GoodsReceipt', 'kind' => 'document', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'supplier_id', 'node_id', 'purchase_order_id', 'business_date', 'received_at', 'currency', 'status',
                'posted_at', 'reversed_at', 'status_reason', 'created_by', 'posted_by', 'reversed_by', 'created_at']],
        'goods_receipt_lines' => ['context' => 'Procurement', 'aggregate' => 'GoodsReceipt', 'kind' => 'line', 'migration' => 'preserve',
            'fingerprint' => ['id', 'goods_receipt_id', 'line_no', 'product_id', 'uom_code', 'received_quantity', 'unit_cost', 'line_cost', 'purchase_order_line_id'],
            'fingerprint_added' => ['manufactured_date', 'best_before_date'],
            'fingerprint_additions' => ['a5c' => ['collection_date', 'supplier_reference'], 'f4a' => ['purchase_uom_code', 'purchase_quantity', 'purchase_unit_cost', 'conversion_factor']]],
        'goods_receipt_dispositions' => ['context' => 'Procurement', 'aggregate' => 'GoodsReceipt', 'kind' => 'line', 'migration' => 'preserve',
            'fingerprint' => ['id', 'goods_receipt_line_id', 'disposition_type', 'quantity', 'target_product_id', 'reason']],

        // ── Inventory ────────────────────────────────────────────────────
        'stock_movements' => ['context' => 'Inventory', 'aggregate' => 'StockLedger', 'kind' => 'append_only', 'migration' => 'preserve',
            'fingerprint' => ['id', 'sequence', 'movement_type', 'product_id', 'node_id', 'bucket', 'direction', 'quantity', 'uom_code', 'unit_cost', 'currency',
                'business_date', 'occurred_at', 'source_document_type', 'source_document_id', 'source_line_id', 'correlation_id', 'actor_user_id', 'reason', 'reverses_movement_id'],
            'fingerprint_added' => ['lot_id']],
        'inventory_lots' => ['context' => 'Inventory', 'aggregate' => 'InventoryLot', 'kind' => 'append_only', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'origin', 'product_id', 'node_id', 'uom_code', 'original_quantity', 'parent_lot_id', 'supplier_id', 'goods_receipt_id',
                'goods_receipt_line_id', 'source_document_type', 'source_document_id', 'business_date', 'received_at', 'manufactured_date', 'best_before_date', 'created_by'],
            'fingerprint_additions' => ['a5c' => ['collection_date', 'supplier_reference'], 'f4a' => ['purchase_uom_code', 'purchase_quantity', 'purchase_unit_cost', 'conversion_factor']]],
        'stock_lot_balances' => ['context' => 'Inventory', 'aggregate' => 'LotBalance', 'kind' => 'projection', 'migration' => 'rebuild'],
        'inventory_operations' => ['context' => 'Inventory', 'aggregate' => 'InventoryOperation', 'kind' => 'append_only', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'operation_type', 'node_id', 'product_id', 'target_product_id', 'quantity', 'uom_code', 'from_bucket',
                'adjustment_direction', 'reason', 'business_date', 'occurred_at', 'actor_user_id'],
            'fingerprint_additions' => ['f3' => ['reason_code', 'reference_text', 'counted_quantity', 'system_quantity']]],
        'reorder_points' => ['context' => 'Inventory', 'aggregate' => 'ReorderPoint', 'kind' => 'master', 'migration' => 'preserve',
            'fingerprint' => ['id', 'product_id', 'node_id', 'min_quantity', 'created_at']],
        'stock_balances' => ['context' => 'Inventory', 'aggregate' => 'StockBalance', 'kind' => 'projection', 'migration' => 'rebuild'],

        // ── Sales ────────────────────────────────────────────────────────
        'sales' => ['context' => 'Sales', 'aggregate' => 'Sale', 'kind' => 'document', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'origin', 'order_reference_id', 'customer_id', 'node_id', 'business_date', 'currency', 'status', 'subtotal',
                'discount_total', 'grand_total', 'paid_amount', 'credited_amount', 'payment_term_days', 'due_date', 'confirmed_at', 'completed_at',
                'reversed_at', 'status_reason', 'created_by', 'confirmed_by', 'reversed_by', 'created_at']],
        'sale_lines' => ['context' => 'Sales', 'aggregate' => 'Sale', 'kind' => 'line', 'migration' => 'preserve',
            'fingerprint' => ['id', 'sale_id', 'line_no', 'product_id', 'uom_code', 'quantity', 'unit_price', 'line_subtotal', 'discount_amount', 'line_total',
                'returned_quantity', 'returned_amount']],
        'customer_returns' => ['context' => 'Sales', 'aggregate' => 'CustomerReturn', 'kind' => 'append_only', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'sale_id', 'customer_id', 'node_id', 'business_date', 'returned_at', 'currency', 'total_value', 'credit_amount',
                'refund_amount', 'refund_method', 'reason', 'actor_user_id']],
        'customer_return_lines' => ['context' => 'Sales', 'aggregate' => 'CustomerReturn', 'kind' => 'append_only', 'migration' => 'preserve',
            'fingerprint' => ['id', 'customer_return_id', 'sale_line_id', 'product_id', 'uom_code', 'quantity', 'value', 'disposition', 'reason']],

        // ── Payment / Receivable (owned by the Sales context in Generation 1) ──
        'payments' => ['context' => 'Payment', 'aggregate' => 'Payment', 'kind' => 'append_only', 'migration' => 'preserve', 'business_key' => 'number',
            'fingerprint' => ['id', 'number', 'sale_id', 'customer_id', 'method', 'amount', 'currency', 'paid_at', 'business_date', 'reference', 'notes', 'actor_user_id']],
        'payment_reversals' => ['context' => 'Payment', 'aggregate' => 'Payment', 'kind' => 'append_only', 'migration' => 'preserve',
            'fingerprint' => ['id', 'payment_id', 'sale_id', 'reason', 'reversed_at', 'business_date', 'actor_user_id']],

        // ── Shared / platform ────────────────────────────────────────────
        'audit_logs' => ['context' => 'Shared', 'aggregate' => 'AuditTrail', 'kind' => 'append_only', 'migration' => 'preserve',
            'fingerprint' => ['id', 'occurred_at', 'business_date', 'actor_user_id', 'actor_label', 'action', 'entity_type', 'entity_id', 'before', 'after', 'context']],
        'document_sequences' => ['context' => 'Shared', 'aggregate' => 'DocumentNumbering', 'kind' => 'counter', 'migration' => 'carry_over'],
        'idempotency_keys' => ['context' => 'Shared', 'aggregate' => 'IdempotentCommand', 'kind' => 'operational', 'migration' => 'discard'],

        // ── Laravel framework ────────────────────────────────────────────
        'migrations' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
        'cache' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
        'cache_locks' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
        'jobs' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
        'job_batches' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
        'failed_jobs' => ['context' => 'Framework', 'aggregate' => '-', 'kind' => 'framework', 'migration' => 'discard'],
    ],
];
