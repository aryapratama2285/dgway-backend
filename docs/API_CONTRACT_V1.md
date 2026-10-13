# API contract v1 (as of Phase A5-C — backend freeze)

This is the contract the Flutter Owner app is built against. It is enforced by
`tests/Feature/Contract/MobileApiContractTest.php` (shapes, envelope, statuses,
documented error codes) plus the feature tests of each context. Anything not
listed here is not part of the contract.

## 1. Transport

| Item | Rule |
| --- | --- |
| Base URL | `https://<host>/api/v1` (HTTPS only in production) |
| Format | JSON request and response bodies; send `Accept: application/json` and `Content-Type: application/json` |
| Auth | `Authorization: Bearer <token>` from `POST /auth/login`. Token TTL = `DGWAY_TOKEN_TTL_DAYS` (default 30). 401 → log in again |
| Commands | every POST/PATCH that changes business data requires `Idempotency-Key: <UUID>` (§5) |
| Correlation | optional `X-Request-Id` (`[A-Za-z0-9._-]{8,64}`); the server echoes it (or a generated UUIDv7) in the response header `X-Request-Id`. Quote it when reporting a problem |
| App version | optional `X-App-Version` (stored in audit context) |
| Rate limits | login: 10/min per IP and 5/min per email; API: 240/min per operator. 429 `TOO_MANY_REQUESTS` |

## 2. Data formats

| Kind | Format | Example |
| --- | --- | --- |
| IDs | UUID string (server-generated, UUIDv7). Never parse or generate business meaning from them | `01a100de-015e-71ee-82f3-8499e95bf950` |
| Document numbers | `PREFIX-YYMMDD-NNN`, server-generated, immutable: SAL, PAY, RET, PO, GR, INV, LOT | `LOT-261003-001` |
| Money | decimal **string**, 4 decimals, currency IDR (`GET /config`) — never a JSON number | `"1275000.0000"` |
| Quantity | decimal **string**, 4 decimals, in the product's base UOM | `"50.0000"` |
| Request numbers | send strings (`"25500"`) or numbers; the server validates precision (money 4 dp, KG 3 dp, PCS 0 dp) |
| Business date | `YYYY-MM-DD`, Asia/Jakarta calendar day (§7) | `2026-10-03` |
| Timestamps | ISO-8601 with offset, UTC | `2026-10-03T08:25:12+00:00` |
| Codes | business codes/SKUs are upper-cased by the server | `TELUR-STD` |

## 3. Pagination

Every list is paginated: `?page=1&per_page=25` (`per_page` ≤ 100; defaults from `GET /config`).

```json
{ "data": [ … ], "meta": { "page": 1, "per_page": 25, "total": 137, "last_page": 6 } }
```

Order is stable per endpoint (documented below). `activity` also returns a `summary` object.

## 4. Errors

```json
{ "error": { "code": "CREDIT_LIMIT_EXCEEDED", "message": "…", "details": { "credit_limit": "5000000.0000", "available_credit": "250000.0000" } } }
```

Branch on `error.code` (stable), show `error.message` (human, may change).
`details` is optional. HTTP statuses and every code: **docs/API_ERROR_CODES.md**.
Validation failures: `422 VALIDATION_FAILED` with `details.fields = { "lines.0.quantity": ["…"] }`.

## 5. Idempotency (commands)

One `Idempotency-Key` per **user action**, reused for every retry of that
action (network error, timeout, app killed). Same key + same body → the
original response replayed byte-for-byte with header `Idempotent-Replayed: true`
and nothing executed again. Same key + different body → `409 IDEMPOTENCY_KEY_REUSED`.
Missing → `428`, malformed → `400`. Full client algorithm: **docs/MOBILE_IDEMPOTENCY.md**.

## 6. Optimistic concurrency (`expected_version`)

Documents and master data carry `version`. Commands that change an existing
document accept `"expected_version": <version from your last read>`; if someone
changed it in between → `409 CONCURRENT_MODIFICATION` (reload, show, let the user
decide). Accepted on: PATCH of master data; PO confirm/cancel; GR post/reverse/cancel;
sale confirm, confirm-with-credit-override, cancel, reverse, payments, returns.
Omit it only when "last writer wins" is acceptable.

## 7. Business date and timezone

The business day is the **Asia/Jakarta** calendar day (`GET /config → business_timezone`).
The server decides the business date of every transaction from its own clock
(`2026-10-03T16:59:59Z` = 23:59:59 WIB → `2026-10-03`; `17:00:00Z` → `2026-10-04`).
The app must not compute it from the phone clock; use `GET /config → business_date`
for "today" and pass `date=` to dashboard/activity for other days.

## 8. Operational defaults — `GET /config` (bearer)

The app reads these at start-up instead of hard-coding them (the node UUID differs per installation).

```json
{ "data": {
  "api_version": "v1", "currency": "IDR", "business_timezone": "Asia/Jakarta",
  "business_date": "2026-10-03", "server_time_utc": "2026-10-03T08:25:10+00:00",
  "default_node": { "id": "…", "code": "DG-WAY-PRIMARY", "name": "DG Way Primary Node" },
  "walk_in_customer": { "id": "…", "code": "WALK-IN", "name": "Pelanggan Umum (Walk-in)" },
  "pagination": { "default_per_page": 25, "max_per_page": 100 },
  "payment_methods": ["CASH", "BANK_TRANSFER", "QRIS", "OTHER"],
  "stock_buckets": ["AVAILABLE", "QUARANTINE", "RETURN_PENDING"],
  "idempotency": { "header": "Idempotency-Key", "format": "UUID", "retention_days": 30 } } }
```

`default_node` / `walk_in_customer` are `null` until `php artisan dgway:bootstrap` ran.
Anonymous walk-in sales are sent with **no `customer_id`** (the WALK-IN partner exists
for reporting/receipts; it is cash-only either way).

## 9. Core mobile flow

### 9.1 `POST /auth/login` (public, throttled)
Request `{ "email", "password", "device_name" }` → `200 { "data": { "token", "token_type": "Bearer", "expires_at", "operator": { "id", "name", "email", "role", "is_active", "permissions" } } }`.
Errors: 401 `INVALID_CREDENTIALS`, 403 `OPERATOR_INACTIVE`, 422, 429. `POST /auth/logout` revokes the current token; `GET /auth/me` returns the operator.

**F5A — roles and permissions.** `role` is `OWNER` or `STAFF`. `permissions` (additive) lists the
sensitive permission values the role holds: every value for OWNER, none for STAFF by default
(`sales.override_credit_limit`, `settings.manage_print`, `inventory.adjust`, `inventory.correct`,
`procurement.close`, `procurement.cancel`, `procurement.reverse`, `catalog.manage_uom_conversion`,
`sales.reverse`, `payments.reverse`, `sales.return`, `partners.manage_credit`, `network.manage`).
The app shows or hides actions from this list; the server authorizes every command again
(403 `ACCESS_DENIED`, `details.permission`). Routine work — sales, payments, purchase orders,
receiving, internal consumption, cash customers, suppliers, products, categories — needs no permission.
Operator administration has no API (server console: `dgway:operator:create --role=`, `:list`,
`:deactivate`, `:reset-password`).

**F5A — `POST /auth/password`** (bearer, throttled 5/min per operator, **no** Idempotency-Key:
the body holds passwords and the idempotency store keeps a hash of command bodies).
Request `{ "current_password", "new_password" }` → `200 { "data": { "password_changed": true, "other_sessions_revoked": 2 } }`.
Every other device token of the operator is revoked; the calling device stays signed in.
Errors: 422 `CURRENT_PASSWORD_INVALID` (never 401), `PASSWORD_TOO_SHORT`, `PASSWORD_UNCHANGED`, `VALIDATION_FAILED`; 429.

### 9.2 Master data
`GET /products?q=&status=active&page=` → `{ id, sku, name, category_id, base_uom{ code, precision }, description, is_active, version }`.
`GET /business-partners?q=&role=customer|supplier&customer_type=&status=` → partner with `supplier`/`customer` role blocks (`term_days`, `credit_limit`, `customer_type`).
Commands (Idempotency-Key): `POST /products`, `PATCH /products/{id}` (+`expected_version`), `/activate`, `/deactivate`; same pattern for categories, business-partners (+ roles), locations, nodes. Nothing is ever deleted.

### 9.3 Purchasing and receiving
- `POST /purchase-orders` `{ supplier_id, node_id, business_date?, lines: [{ product_id, quantity, unit_cost, uom? }] }` → 201 `{ id, number, status: "DRAFT", total_amount, version, lines[{ id, … }] }`; `POST /purchase-orders/{id}/confirm`.
- `POST /goods-receipts` `{ supplier_id, node_id, purchase_order_id?, business_date?, received_at?, lines: [{ product_id, purchase_order_line_id?, quantity, unit_cost, manufactured_date?, best_before_date?, collection_date?, supplier_reference?, dispositions: [{ type: ACCEPT|LOSS|RETURN_TO_SUPPLIER|REGRADE, quantity, target_product_id? (REGRADE), reason? }] }] }` — Σ dispositions = quantity. → 201 DRAFT `{ id, number, status, version, total_cost, lines[{ id, received_quantity, unit_cost, line_cost, dispositions[], manufactured_date, best_before_date, collection_date, supplier_reference }] }`.
  **Upstream facts (A5-C), all optional, never inferred:** `collection_date` (`YYYY-MM-DD`) = the date the supplier/farm states the goods were collected (eggs: collected/separated) — NOT the production date and NOT the DG Way received date; `supplier_reference` (≤ 60 chars) = the supplier's own batch/reference, only if the supplier really gives one. Omit or send `null` when unknown (agent/distributor purchases): it stays `null` forever and is never copied from the receipt date. Rules: `collection_date` ≤ receipt `business_date` and ≥ `manufactured_date` when both are given → otherwise `422 COLLECTION_DATE_INVALID` (`details.rule` = `not_after_receipt` | `not_before_manufactured`). No minimum age is enforced (eggs held at the farm are normal).
- `POST /goods-receipts/{id}/post` `{ expected_version? }` → 200 POSTED. **Creates the lots** (one per line + derived lots for REGRADE) and the ledger movements. Errors: 409 `INVALID_STATUS_TRANSITION` (already posted), 422 `MASS_BALANCE_VIOLATED`, `OVER_RECEIPT`.
- `POST /goods-receipts/{id}/reverse` `{ reason }` (posted → REVERSED, compensating movements, lot becomes DEPLETED); `/cancel` (draft only).

### 9.3a Procurement lifecycle & purchasing units (F4A)

- **States.** PO: `DRAFT → CONFIRMED → PARTIALLY_RECEIVED → RECEIVED`, plus `CANCELLED` (nothing received) and **`CLOSED`** (finished, possibly short). `CLOSED` and `CANCELLED` are terminal and read-only (also enforced by a trigger). GR: `DRAFT → POSTED` (`REVERSED`, `CANCELLED` unchanged).
- `PATCH /purchase-orders/{id}` `{ expected_version, supplier_id?, node_id?, business_date?, notes?, lines? }` — DRAFT only. Absent fields are unchanged; `lines` replaces all lines. Otherwise → 409 `INVALID_STATUS_TRANSITION` (`details.action = "edit"`).
- `POST /purchase-orders/{id}/close` `{ reason_code: SUPPLIER_SHORTFALL|PURCHASE_STOPPED|OTHER, note? (required for OTHER), expected_version? }` — PARTIALLY_RECEIVED or RECEIVED only (permission `procurement.close`). Ordered stays as ordered; `lines[].unfulfilled_quantity` = ordered − received. Draft receipts left on a closed order cannot be posted (409 `INVALID_STATUS_TRANSITION`; creating a new one → 422 `PO_NOT_RECEIVABLE`).
- `PATCH /goods-receipts/{id}` `{ expected_version, business_date?, received_at?, notes?, lines? }` — DRAFT only. Supplier, node and PO are fixed: cancel the draft to change them.
- **Purchasing units.** On PO and GR lines, `uom` is the unit the line is bought/counted in (default: the base unit) and `unit_cost` is the price/cost **per that unit**. A non-base unit needs an active conversion: `GET /products/{id}/uom-conversions`, `PUT /products/{id}/uom-conversions/{uom}` `{ factor_to_base, is_active?, expected_version? }` (permission `catalog.manage_uom_conversion`). Lines keep a snapshot — `purchase_uom`, `purchase_quantity`, `purchase_unit_price` (PO) / `purchase_unit_cost` (GR), `conversion_factor` — and the base figures `uom`, `ordered_quantity` / `received_quantity`, `unit_cost`.
  - Base quantity = quantity × factor, exact (`UOM_CONVERSION_INVALID` otherwise).
  - `line_total` / `line_cost` = quantity × unit price, rounded to 4 decimals.
  - `unit_cost` (per base unit, the lot/HPP cost basis) = price ÷ factor, half-up to 4 decimals.
  - A receipt against a PO line counted in the PO line's unit uses the PO line's conversion snapshot and defaults its cost to the agreed price.
- **Quality split.** Disposition `QUARANTINE` → RECEIPT into the QUARANTINE bucket (held, not sellable; released with `POST /inventory/recoveries` `from_bucket: QUARANTINE`). E.g. 100 received = ACCEPT 95 + QUARANTINE 5.
- **Read additions (additive).** PO: `supplier{}`, `node{}`, `lines[].product{}`, `confirmed_at`, `cancelled_at`, `closed_at`, `close_reason_code`, `close_note`, `receiving{ lines_total, lines_fully_received, lines_with_receipts, receipts, posted_receipts }`. GR: `supplier{}`, `node{}`, `purchase_order{ id, number, status }`, `lines[].product{}`, `lines[].lot{ id, number }`. `GET /purchase-orders?status=OPEN` = CONFIRMED + PARTIALLY_RECEIVED.
- **Owner views.** Activity adds `PURCHASE_ORDER_CONFIRMED`, `PURCHASE_ORDER_CLOSED` (detail = reason code), `PURCHASE_ORDER_CANCELLED`. Dashboard adds `purchasing.open_orders_now{ count, partially_received }` (current) and `received_quantity[].quarantine`.
- **Permissions.** `procurement.close`, `procurement.cancel`, `procurement.reverse` (GR reversal), `catalog.manage_uom_conversion`. Routine actions (create, edit, confirm, receive, post) need none, per the registry rule. OWNER has `*`.

### 9.4 Sales, payments, returns
- `POST /sales` `{ customer_id?, node_id, business_date?, notes?, lines: [{ product_id, quantity, unit_price, discount?, uom? }], confirm?: bool, payments?: [{ method, amount, reference?, paid_at? }] }`.
  `confirm=false` → 201 DRAFT; `confirm=true` → 201 CONFIRMED/COMPLETED in one transaction (stock leaves FIFO by lot, payments recorded, credit checked).
  Response: `{ id, number, status, payment_status, version, customer{ id, code, name }|null, is_walk_in, node_id, business_date, currency, subtotal, discount_total, grand_total, paid, credited, outstanding, payment_term_days, due_date, is_overdue, confirmed_at, completed_at, reversed_at, lines[{ id, line_no, product_id, uom, quantity, unit_price, subtotal, discount, total, returned_quantity, returned_amount, returnable_quantity }] }`.
  Errors: 409 `INSUFFICIENT_STOCK` (details.available/requested), 409 `CREDIT_LIMIT_EXCEEDED` (details.credit_limit/existing_outstanding/requested_credit/available_credit), 422 `CREDIT_NOT_ALLOWED` / `WALK_IN_MUST_BE_PAID`, 409 `PAYMENT_EXCEEDS_OUTSTANDING`, 422 `PRODUCT_INACTIVE`/`CUSTOMER_NOT_USABLE`.
- `POST /sales/{id}/confirm` `{ payments?, expected_version? }` (DRAFT → final).
- `POST /sales/{id}/confirm-with-credit-override` `{ reason (5–500), payments?, expected_version? }` — OWNER-only (permission `sales.override_credit_limit`), only when the limit IS exceeded (`409 CREDIT_OVERRIDE_NOT_REQUIRED` otherwise), never for cash/walk-in customers, never changes the credit limit; audited as `sale.credit_limit_overridden`. 403 `ACCESS_DENIED` without the permission.
- `POST /sales/{id}/payments` `{ method, amount, reference?, paid_at?, expected_version? }` → 201 `{ id, number, status, method, amount, …, sale: { …sale } }`. `POST /payments/{id}/reverse` `{ reason }` → 200 (409 `PAYMENT_ALREADY_REVERSED` on a second attempt).
- `POST /sales/{id}/returns` `{ reason, refund_method?, lines: [{ sale_line_id, quantity, disposition: RETURN_TO_AVAILABLE|RETURN_TO_QUARANTINE|LOSS }] }` → 201 return; stock goes back into the lots the customer received (BATCH_LOT_MODEL.md §6).
- `POST /sales/{id}/cancel` `{ reason }` (draft), `POST /sales/{id}/reverse` `{ reason, reverse_payments? }` (final, no returns).
- Reads: `GET /sales?status=&payment_status=&customer_id=&walk_in=&date_from=&date_to=&q=`, `GET /sales/{id}`, `GET /payments?sale_id=`, `GET /customer-returns`, `GET /receivables` (by sale), `GET /receivables/customers`, `GET /receivables/summary`, `GET /reports/daily|sales|sales-by-product`.

### 9.4a Sales & basic finance (F4B)

- **Payment method `OTHER`** next to `CASH`, `BANK_TRANSFER`, `QRIS` (payments and refund methods; `GET /config` → `payment_methods`, dashboard `payments.by_method.other`). An `OTHER` payment must carry a non-empty `reference` or `notes` (e.g. "Potong deposit customer") → 422 `PAYMENT_REFERENCE_REQUIRED`; also a DB CHECK.
- **`GET /sales/{id}`** (detail only, additive): `lines[].product{ id, sku, name }` and `lines[].lots[{ lot_id, lot_number, consumed, returned }]` — the lots the line consumed FIFO, in allocation order, derived from the ledger (SALE movements not reversed; CUSTOMER_RETURN movements). Command responses and `GET /sales` are unchanged.
- **Permissions** (403 `ACCESS_DENIED` without them; OWNER has `*`): `sales.reverse` (`POST /sales/{id}/reverse`), `payments.reverse` (`POST /payments/{id}/reverse`), `sales.return` (`POST /sales/{id}/returns`).
- Unchanged: sale lifecycle (`DRAFT → CONFIRMED|COMPLETED`, `CANCELLED`, `REVERSED`), `payment_status` (`UNPAID|PARTIALLY_PAID|PAID|NONE`, separate from the status), receivable derivation, base-UOM-only sales (`UOM_NOT_BASE`), credit limit and override.

### 9.5 Inventory and lots
- `GET /inventory/current?product_id&node_id` → `{ available, quarantine, return_pending, on_hand, ledger_on_hand, uom }`; `GET /inventory/balances`, `GET /inventory/movements`, `GET /inventory/integrity`.
- Operations (Idempotency-Key): `POST /inventory/losses|regrades|recoveries|supplier-returns|adjustments` `{ node_id, product_id, quantity, reason, uom?, to_product_id? (regrade), from_bucket? (recovery), direction? (adjustment) }` — lots are consumed FIFO by the server.
- `GET /lots?q=&product_id=&node_id=&supplier_id=&goods_receipt_id=&origin=&status=active|depleted|all&date_from=&date_to=` — newest first. `q` searches lot number, SKU, product name, supplier code/name, GR number (case-insensitive).
  Item: `{ id, number, origin, status (derived), product{ id, sku, name }, node{ id, code }, uom, original_quantity|null, remaining{ on_hand, available, quarantine, return_pending }, supplier|null, goods_receipt{ id, number, line_id }|null, parent_lot_id, source{ type, id }, business_date, received_at, manufactured_date, best_before_date, collection_date|null, supplier_reference|null, provenance: RECORDED|UNATTRIBUTED_LEGACY, created_at }`. `business_date`/`received_at` = when **DG Way received** the goods; `collection_date` = upstream collection (only when recorded). A lot number is DG Way's internal identity, never a supplier batch number.
- `GET /lots/{id-or-number}` → item + `parent`, `children[]`, `remaining_by_bucket[]`, `dispositions[]` (of the source GR line).
- `GET /lots/{id}/movements` (newest first; `legacy_unattributed` marks pre-A5-B rows), `GET /lots/{id}/lineage` → `{ ancestors[], descendants[] }`, `GET /lots/{id}/destinations` → per sale line `{ sale_id, sale_number, business_date, sale_status, customer|null, sold, reversed, returned, net, uom }` (FIFO order).
- `GET /inventory/lot-balances?product_id&node_id&bucket&include_zero` — per lot × bucket in FIFO order.

### 9.5a Inventory operations & internal consumption (F3)

All quantities are decimal strings in the product's **base UOM** (`UOM_NOT_BASE` otherwise; product precision enforced). Stock never goes negative (409 `INSUFFICIENT_STOCK`); lots are consumed **FIFO automatically** (no lot parameter). Every command needs `Idempotency-Key`.

- `POST /inventory/consumptions` `{ node_id, product_id, quantity, reason_code: PACKING|LABELING|DELIVERY|ADMIN|SAMPLE|OTHER, notes?, reference_text? (≤ 60), uom? }` → 201 operation `{ id, number (INV-…), type: "INTERNAL_CONSUMPTION", node_id, product_id, quantity, uom, from_bucket: "AVAILABLE", direction: null, reason, reason_code, reference_text, counted_quantity: null, system_quantity: null, business_date, occurred_at }`. DG Way used its own material: one `INTERNAL_CONSUMPTION` movement (OUT, AVAILABLE) per consumed lot. It is **not** a LOSS (dashboard `losses_on_date` is unaffected) and not an adjustment. `reason` = the notes, or the reason code when no notes are given. `reference_text` is an operational note (e.g. an order number), **not** an accounting link. Any classification may be consumed; classification is context, not a permission. Authorization = an active operator (same as loss).
- `POST /inventory/adjustments` — two mutually exclusive modes, both need permission **`inventory.adjust`** (403 `ACCESS_DENIED`), `reason` required, `reason_code?` = COUNT_CORRECTION|FOUND_STOCK|SHRINKAGE|OTHER (damage = `POST /inventory/losses`):
  - delta (A3, unchanged): `{ node_id, product_id, quantity, direction: IN|OUT, reason, reason_code?, uom? }`;
  - **counted** (stock opname): `{ node_id, product_id, counted_quantity, expected_available?, reason, reason_code?, uom? }`. The server locks the AVAILABLE position, reads the system quantity, and posts `counted − system` as ADJUSTMENT IN (new ADJUSTMENT lot, cost basis unknown) or OUT (FIFO). 422 `ADJUSTMENT_NOT_REQUIRED` when equal; 409 `STOCK_CHANGED_SINCE_COUNT` when `expected_available` ≠ the locked system quantity. The response carries `counted_quantity`, `system_quantity`, `direction`, `quantity`.
- `GET /inventory/operations?type=&product_id=&node_id=&reason_code=&date_from=&date_to=&page=` → newest first: operation fields + `product{ id, sku, name, classification }`, `node{ id, code, name }`, `actor_user_id`, `created_at`. `GET /inventory/operations/{id}` → + `movements[{ id, movement_type, product_id, bucket, direction, quantity, uom, lot{ id, number, origin }|null }]` (the FIFO lots used).
- `GET /inventory/stock?node_id=&product_id=&q=&classification=SELLABLE|PACKAGING|CONSUMABLE&low_stock=&include_zero=&page=` → one row per product × stock-holding node, ordered by product name, SKU, node code: `{ product{ id, sku, name, classification, is_active }, node{ id, code, name }, uom{ code, precision }, available, quarantine, return_pending, on_hand, reorder_point|null, is_low_stock }`. A read model over `stock_balances` (projection) and master data. Default: positions holding stock or at/below their reorder point; `include_zero=1` (or `product_id`) also lists active products without stock.
- Low stock: a reorder point is set (`null` = monitoring disabled), product active, node active with INVENTORY, and **AVAILABLE ≤ min_quantity**. Same rule for `low_stock=1` and dashboard `inventory.low_stock_now` (a count of product × node **positions**, not distinct products).
- `GET /inventory/reorder-points?product_id=&node_id=&page=` → `{ id, product_id, node_id, min_quantity|null, uom (the product's base UOM, derived), version, updated_by, updated_at }`.
- `POST /inventory/reorder-points` `{ product_id, node_id, min_quantity: "decimal"|null, expected_version? }` → 200 `{ id, product_id, node_id, min_quantity, uom, version }`. Upsert per product × node; `null` clears (row kept, version + 1); audited `inventory.reorder_point_set`; 409 `CONCURRENT_MODIFICATION` on a stale `expected_version`. A product's `base_uom` cannot change while it has an active reorder point (422 `BASE_UOM_LOCKED_BY_REORDER_POINT`).
- Additive fields: `GET /inventory/movements` items gain `product_name`, `classification`, `lot{ id, number }|null`, `source.number`, `reason_code`; `GET /dashboard` → `inventory.low_stock_now`; `GET /activity` gains types `INTERNAL_CONSUMPTION` and `INVENTORY_ADJUSTMENT` (`detail` = `[IN|OUT] reason_code`, `quantity`, document = the INV operation).

### 9.6 Owner views
- `GET /dashboard?date=YYYY-MM-DD` (default today) → `{ business_date, timezone, currency, sales{ count, total, walk_in_count, returns_value, net_sales, reversed_count }, payments{ count, total, reversed, refunded, net_received, by_method{ cash|bank_transfer|qris: { received, reversed, refunded } } }, receivables{ as_of, total, not_yet_due, due_today, overdue, open_sales, customers }, purchasing{ purchase_orders{ count, total }, goods_receipts{ count, cost }, received_quantity[{ uom, received, accept, loss, return_to_supplier, regrade }] }, inventory{ as_of, stock_by_uom[{ uom, available, quarantine, return_pending }], products_in_stock, losses_on_date, sold_quantity_on_date, active_lots_now } }`. No HPP/margin.
  Receivables and stock are reconstructed **as of the end of that date** from dated facts; `active_lots_now` is current.
- `GET /activity?date=&type=` → events of the day, newest first: `{ type, occurred_at, document{ type, id, number }, amount, quantity, counterparty{ code, name }|null, method, detail, actor_user_id }`, types `PURCHASE_ORDER, GOODS_RECEIPT, LOSS, SALE, PAYMENT, CUSTOMER_RETURN, PAYMENT_REVERSAL, SALE_REVERSAL, GOODS_RECEIPT_REVERSAL`; plus `summary.receivable_change{ opening, closing, change }`.
- `GET /search?q=(≥2 chars)&type=all|products|partners|documents` → `{ type, id, code, label, subtitle, active }`, exact match first, then prefix, then contains. Partners match code, name or phone (`0812…` finds `+62812…`); documents match number **prefix** (SAL-, PAY-, RET-, PO-, GR-, INV-, LOT-).

### 9.7 Printing and scanning (A5-C) — bearer, read-only, no Idempotency-Key

Full contract: **docs/PRINTING_SCANNING_CONTRACT.md** and **docs/QR_BARCODE_CONTRACT.md**. The backend returns
device-neutral print DATA; the app renders it (ESC/POS, Bluetooth, label printer, camera are the app's).

Every print document uses one envelope:

```json
{ "data": { "schema_version": 1, "document_type": "RECEIPT | LOT_LABEL | PURCHASE_ORDER | GOODS_RECEIPT",
  "generated_at": "…", "business": { "business_name", "business_subtitle", "address", "phone", "footer_text" },
  "layout": { "medium": "RECEIPT", "paper_width_mm": 80, "supported_widths_mm": [58, 80] }  |  { "medium": "LABEL" },
  "document": { … } } }
```

Money appears as `{ "amount": "1275000.0000", "formatted": "Rp1.275.000" }` and quantities as
`{ "value": "50.0000", "formatted": "50", "uom": "KG" }`: print `formatted`, never recompute.

| Endpoint | Returns |
| --- | --- |
| `GET /print/settings` | business identity + `receipt_width_mm` (80 default, 58 allowed), `supported_receipt_widths_mm`, `version`, `is_default` |
| `PATCH /print/settings` (Idempotency-Key, permission `settings.manage_print`) | `{ business_name?, business_subtitle?, address?, phone?, footer_text?, receipt_width_mm? (58\|80), expected_version? }`; absent = unchanged, `null` = clear; 403 `ACCESS_DENIED`, 409 `CONCURRENT_MODIFICATION`, 422 |
| `GET /sales/{id}/print?width=58\|80` | `RECEIPT`: sale id/number/status/`is_final`/`notice`, business date, `transaction_at`, `is_walk_in`, `customer{code,name}\|null`, operator, lines (sku, name, quantity, unit_price, discount, line_total), totals (subtotal, discount_total, grand_total, total_paid, credited, outstanding), payments (number, method, method_label, amount, paid_at, reference, `reversed`), `payment_terms\|null`, returns, `reference{document_number, qr_payload}` |
| `GET /purchase-orders/{id}/print` | `PURCHASE_ORDER`: supplier, lines (ordered/received quantity, unit cost, line total), total |
| `GET /goods-receipts/{id}/print` | `GOODS_RECEIPT`: supplier, PO number, business date, received_at, lines with dispositions, `collection_date`, `supplier_reference`, generated `lot{ id, number, qr_payload, barcode }` |
| `GET /lots/{id-or-number}/label` | `LOT_LABEL`: lot id/number/origin, product, `received_by_dgway{ business_date, received_at }`, `collection_date\|null`, `supplier_reference\|null`, original quantity, supplier/GR/parent (or null), best-before, `provenance`, ordered `display_lines[{ key, label, value }]`, `codes{ qr{ payload, contract_version }, barcode{ symbology: CODE128, value } }` |
| `GET /scan/resolve?code=` | `{ type: LOT\|PRODUCT\|SALE, id, matched_by: QR\|LOT_NUMBER\|SKU, display{…}, links{…} }`; 404 `SCAN_CODE_UNKNOWN`, 409 `SCAN_CODE_AMBIGUOUS` (`details.candidate_types`), 422 `SCAN_CODE_INVALID` / `SCAN_VERSION_UNSUPPORTED`, 401 without token |

Label wording is fixed by the backend: the received date is always "Diterima DG Way"; "Tanggal Pengumpulan"
appears only when a collection date was recorded; LEGACY lots show "Asal: Tidak tercatat". The app prints
`display_lines` as given (or lays out the structured fields with the same labels).

### 9.8 Health
- `GET /health` (public) → `{ status, database, server_time_utc, business_date, business_timezone, api_version }`, 503 when the DB is down.
- `GET /health/ready` (public) → `{ status: ready|not_ready, checks{ database, migrations, app_key, default_node, timezone } }`, 503 when not ready. Never returns messages, hosts or versions.

## 10. Endpoint catalog (generated from the route table)

Kept in sync by `MobileApiContractTest::test_every_api_route_is_documented_in_the_contract`.

| Method | Path (/api/v1/…) | Auth | Idempotency-Key | Handler |
| --- | --- | --- | --- | --- |
| GET | `activity` | bearer | — | OperationsController@activity |
| POST | `auth/login` | public | — | AuthController@login |
| POST | `auth/logout` | bearer | — | AuthController@logout |
| GET | `auth/me` | bearer | — | AuthController@me |
| POST | `auth/password` | bearer | — | AuthController@changePassword |
| GET | `business-partners` | bearer | — | BusinessPartnerController@index |
| POST | `business-partners` | bearer | yes | BusinessPartnerController@store |
| GET | `business-partners/{id}` | bearer | — | BusinessPartnerController@show |
| PATCH | `business-partners/{id}` | bearer | yes | BusinessPartnerController@update |
| POST | `business-partners/{id}/activate` | bearer | yes | BusinessPartnerController@activate |
| POST | `business-partners/{id}/deactivate` | bearer | yes | BusinessPartnerController@deactivate |
| POST | `business-partners/{id}/roles` | bearer | yes | BusinessPartnerController@assignRole |
| PATCH | `business-partners/{id}/roles/{role}` | bearer | yes | BusinessPartnerController@updateRole |
| POST | `business-partners/{id}/roles/{role}/activate` | bearer | yes | BusinessPartnerController@activateRole |
| POST | `business-partners/{id}/roles/{role}/deactivate` | bearer | yes | BusinessPartnerController@deactivateRole |
| GET | `categories` | bearer | — | CategoryController@index |
| POST | `categories` | bearer | yes | CategoryController@store |
| GET | `categories/{id}` | bearer | — | CategoryController@show |
| PATCH | `categories/{id}` | bearer | yes | CategoryController@update |
| POST | `categories/{id}/activate` | bearer | yes | CategoryController@activate |
| POST | `categories/{id}/deactivate` | bearer | yes | CategoryController@deactivate |
| GET | `config` | bearer | — | OperationsController@config |
| GET | `customer-returns` | bearer | — | CustomerReturnController@index |
| GET | `customer-returns/{id}` | bearer | — | CustomerReturnController@show |
| GET | `dashboard` | bearer | — | OperationsController@dashboard |
| GET | `goods-receipts` | bearer | — | GoodsReceiptController@index |
| POST | `goods-receipts` | bearer | yes | GoodsReceiptController@store |
| GET | `goods-receipts/{id}` | bearer | — | GoodsReceiptController@show |
| POST | `goods-receipts/{id}/cancel` | bearer | yes | GoodsReceiptController@cancel |
| PATCH | `goods-receipts/{id}` | bearer | yes | GoodsReceiptController@update |
| POST | `goods-receipts/{id}/post` | bearer | yes | GoodsReceiptController@post |
| GET | `goods-receipts/{id}/print` | bearer | — | PrintController@goodsReceipt |
| POST | `goods-receipts/{id}/reverse` | bearer | yes | GoodsReceiptController@reverse |
| GET | `health` | public | — | HealthController |
| GET | `health/ready` | public | — | HealthController@ready |
| POST | `inventory/adjustments` | bearer | yes | InventoryController@adjustment |
| GET | `inventory/balances` | bearer | — | InventoryController@balances |
| GET | `inventory/current` | bearer | — | InventoryController@current |
| GET | `inventory/integrity` | bearer | — | InventoryController@integrity |
| POST | `inventory/losses` | bearer | yes | InventoryController@loss |
| GET | `inventory/lot-balances` | bearer | — | LotController@balances |
| GET | `inventory/movements` | bearer | — | InventoryController@movements |
| POST | `inventory/consumptions` | bearer | yes | InventoryController@consumption |
| GET | `inventory/operations` | bearer | — | InventoryController@operations |
| GET | `inventory/operations/{id}` | bearer | — | InventoryController@operation |
| GET | `inventory/reorder-points` | bearer | — | ReorderPointController@index |
| POST | `inventory/reorder-points` | bearer | yes | ReorderPointController@store |
| GET | `inventory/stock` | bearer | — | InventoryController@stock |
| POST | `inventory/recoveries` | bearer | yes | InventoryController@recovery |
| POST | `inventory/regrades` | bearer | yes | InventoryController@regrade |
| POST | `inventory/supplier-returns` | bearer | yes | InventoryController@supplierReturn |
| GET | `locations` | bearer | — | LocationController@index |
| POST | `locations` | bearer | yes | LocationController@store |
| GET | `locations/{id}` | bearer | — | LocationController@show |
| PATCH | `locations/{id}` | bearer | yes | LocationController@update |
| POST | `locations/{id}/activate` | bearer | yes | LocationController@activate |
| POST | `locations/{id}/deactivate` | bearer | yes | LocationController@deactivate |
| GET | `lots` | bearer | — | LotController@index |
| GET | `lots/{lot}` | bearer | — | LotController@show |
| GET | `lots/{lot}/destinations` | bearer | — | LotController@destinations |
| GET | `lots/{lot}/label` | bearer | — | PrintController@lotLabel |
| GET | `lots/{lot}/lineage` | bearer | — | LotController@lineage |
| GET | `lots/{lot}/movements` | bearer | — | LotController@movements |
| GET | `nodes` | bearer | — | NodeController@index |
| POST | `nodes` | bearer | yes | NodeController@store |
| GET | `nodes/{id}` | bearer | — | NodeController@show |
| PATCH | `nodes/{id}` | bearer | yes | NodeController@update |
| POST | `nodes/{id}/activate` | bearer | yes | NodeController@activate |
| POST | `nodes/{id}/deactivate` | bearer | yes | NodeController@deactivate |
| GET | `payments` | bearer | — | PaymentController@index |
| GET | `payments/{id}` | bearer | — | PaymentController@show |
| POST | `payments/{id}/reverse` | bearer | yes | PaymentController@reverse |
| GET | `print/settings` | bearer | — | PrintController@settings |
| PATCH | `print/settings` | bearer | yes | PrintController@updateSettings |
| GET | `products` | bearer | — | ProductController@index |
| POST | `products` | bearer | yes | ProductController@store |
| GET | `products/{id}` | bearer | — | ProductController@show |
| GET | `products/{id}/uom-conversions` | bearer | — | ProductUomConversionController@index |
| PUT | `products/{id}/uom-conversions/{uom}` | bearer | yes | ProductUomConversionController@put |
| PATCH | `products/{id}` | bearer | yes | ProductController@update |
| POST | `products/{id}/activate` | bearer | yes | ProductController@activate |
| POST | `products/{id}/deactivate` | bearer | yes | ProductController@deactivate |
| GET | `purchase-orders` | bearer | — | PurchaseOrderController@index |
| POST | `purchase-orders` | bearer | yes | PurchaseOrderController@store |
| GET | `purchase-orders/{id}` | bearer | — | PurchaseOrderController@show |
| POST | `purchase-orders/{id}/cancel` | bearer | yes | PurchaseOrderController@cancel |
| POST | `purchase-orders/{id}/close` | bearer | yes | PurchaseOrderController@close |
| PATCH | `purchase-orders/{id}` | bearer | yes | PurchaseOrderController@update |
| POST | `purchase-orders/{id}/confirm` | bearer | yes | PurchaseOrderController@confirm |
| GET | `purchase-orders/{id}/print` | bearer | — | PrintController@purchaseOrder |
| GET | `receivables` | bearer | — | ReceivableController@bySale |
| GET | `receivables/customers` | bearer | — | ReceivableController@byCustomer |
| GET | `receivables/summary` | bearer | — | ReceivableController@summary |
| GET | `reports/daily` | bearer | — | ReportController@daily |
| GET | `reports/sales` | bearer | — | ReportController@sales |
| GET | `reports/sales-by-product` | bearer | — | ReportController@salesByProduct |
| GET | `sales` | bearer | — | SaleController@index |
| POST | `sales` | bearer | yes | SaleController@store |
| GET | `sales/{id}` | bearer | — | SaleController@show |
| POST | `sales/{id}/cancel` | bearer | yes | SaleController@cancel |
| POST | `sales/{id}/confirm` | bearer | yes | SaleController@confirm |
| POST | `sales/{id}/confirm-with-credit-override` | bearer | yes | SaleController@confirmWithCreditOverride |
| POST | `sales/{id}/payments` | bearer | yes | SaleController@pay |
| GET | `sales/{id}/print` | bearer | — | PrintController@receipt |
| POST | `sales/{id}/returns` | bearer | yes | SaleController@recordReturn |
| POST | `sales/{id}/reverse` | bearer | yes | SaleController@reverse |
| GET | `scan/resolve` | bearer | — | PrintController@resolve |
| GET | `search` | bearer | — | OperationsController@search |
| GET | `uoms` | bearer | — | ProductController@uoms |

