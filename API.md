# API (v1) — implemented through Phase A5-B

> The authoritative mobile contract is **docs/API_CONTRACT_V1.md** (with docs/API_ERROR_CODES.md
> and docs/MOBILE_IDEMPOTENCY.md). This file is the per-phase overview.

Base: `https://<host>/api/v1` · JSON only · times in ISO-8601 UTC.

## Conventions

- **Auth**: `Authorization: Bearer <token>` from `/auth/login`.
- **Errors**: always
  ```json
  { "error": { "code": "STABLE_CODE", "message": "human text", "details": {} } }
  ```
- **Idempotency**: every mutating business endpoint (all POST/PATCH below
  except login/logout) requires `Idempotency-Key: <uuid>`; the app reuses the
  same key when retrying the same action. Replays return the original
  response byte-for-byte with `Idempotent-Replayed: true`. See docs/IDEMPOTENCY.md.
- **Optimistic concurrency**: PATCH bodies may include `"expected_version": n`
  (from the last read). If someone saved in between → `409 CONCURRENT_MODIFICATION`.
- **Lists** accept `status=active|inactive|all` (default `all`); paged lists
  accept `page`, `per_page` (≤ 100) and return `meta {page, per_page, total, last_page}`.

| Status | code | Meaning |
| --- | --- | --- |
| 400 | IDEMPOTENCY_KEY_INVALID | malformed key |
| 401 | UNAUTHENTICATED / INVALID_CREDENTIALS | no/invalid token, wrong login |
| 403 | OPERATOR_INACTIVE / FORBIDDEN | account disabled / not allowed |
| 404 | NOT_FOUND | |
| 409 | IDEMPOTENCY_KEY_REUSED | same key, different request |
| 409 | DUPLICATE_CODE | SKU / code already exists |
| 409 | CONCURRENT_MODIFICATION | stale `expected_version` |
| 422 | VALIDATION_FAILED or a domain rule code | e.g. QUANTITY_NOT_POSITIVE |
| 428 | IDEMPOTENCY_KEY_REQUIRED | header missing on a command |
| 429 | TOO_MANY_REQUESTS | rate limited |
| 500 | INTERNAL_ERROR | nothing was saved; `details.reference` identifies the server log entry |

## Endpoints (Phase A1)

### `GET /health` — public
```json
{ "data": { "status": "ok", "database": "up", "server_time_utc": "…",
            "business_date": "2026-10-03", "business_timezone": "Asia/Jakarta", "api_version": "v1" } }
```

### `POST /auth/login` — public, rate limited
Request: `{ "email": "…", "password": "…", "device_name": "Android Owner 1" }`
Response 200:
```json
{ "data": { "token": "12|…", "token_type": "Bearer", "expires_at": "…",
            "operator": { "id": "uuid", "name": "…", "email": "…", "role": "OWNER", "is_active": true } } }
```

### `GET /auth/me` — authenticated
Returns `{ "data": <operator> }`.

### `POST /auth/logout` — authenticated
Revokes the token used for this request. `204 No Content`.

## Master data (Phase A2) — all authenticated

| Method | Path | Notes |
| --- | --- | --- |
| GET | /uoms | reference units |
| GET | /categories | `status` |
| GET | /categories/{id} | |
| POST | /categories | `{code, name, parent_id?}` |
| PATCH | /categories/{id} | `{name?, parent_id?, expected_version?}` (code immutable) |
| POST | /categories/{id}/activate · /deactivate | deactivate refused while active children/products exist |
| GET | /products | `status, q (name/SKU), category_id, page, per_page` |
| GET | /products/{id} | |
| POST | /products | `{sku, name, category_id, base_uom, description?}` |
| PATCH | /products/{id} | `{name?, category_id?, base_uom?, description?, expected_version?}` (SKU immutable) |
| POST | /products/{id}/activate · /deactivate | |
| GET | /business-partners | `status, role=SUPPLIER|CUSTOMER, customer_type, q (name/code/phone in any format), page, per_page` |
| GET | /business-partners/{id} | |
| POST | /business-partners | `{code, name, party_type, legal_name?, phone?, email?, address?, notes?, supplier?: {term_days?, notes?}, customer?: {customer_type, term_days?, credit_limit?, notes?}}` — at least one role |
| PATCH | /business-partners/{id} | `{name?, legal_name?, phone?, email?, address?, notes?, expected_version?}` |
| POST | /business-partners/{id}/activate · /deactivate | |
| POST | /business-partners/{id}/roles | `{role, customer_type?, term_days?, credit_limit?, notes?}` |
| PATCH | /business-partners/{id}/roles/{supplier|customer} | terms / type / notes |
| POST | /business-partners/{id}/roles/{supplier|customer}/activate · /deactivate | |
| GET | /locations · /locations/{id} | |
| POST | /locations | `{code, name, address?, notes?}` |
| PATCH | /locations/{id} | `{name?, address?, notes?, expected_version?}` |
| POST | /locations/{id}/activate · /deactivate | deactivate refused while active nodes exist |
| GET | /nodes | `status, capability, location_id` |
| GET | /nodes/{id} | |
| POST | /nodes | `{code, name, location_id?, capabilities[], notes?}` |
| PATCH | /nodes/{id} | `{name?, location_id?, capabilities?, notes?, expected_version?}` |
| POST | /nodes/{id}/activate · /deactivate | |

Address object: `{street, village?, district?, city?, province?, postal_code?, landmark?, latitude?, longitude?}`.
There are no DELETE endpoints for master data.

## Procurement & inventory (Phase A3) — all authenticated

All POST endpoints below require `Idempotency-Key`. Quantities/amounts are
decimal strings with 4 decimals in responses (`"140.0000"`).

| Method | Path | Notes |
| --- | --- | --- |
| GET | /purchase-orders | `status, supplier_id, node_id, date_from, date_to, page, per_page` |
| GET | /purchase-orders/{id} | lines with ordered / received / open quantity and line totals |
| POST | /purchase-orders | `{supplier_id, node_id, business_date?, notes?, lines: [{product_id, quantity, uom?, unit_cost}]}` → DRAFT, number `PO-YYMMDD-NNN` |
| POST | /purchase-orders/{id}/confirm | `{expected_version?}` |
| POST | /purchase-orders/{id}/cancel | `{reason, expected_version?}` — refused once anything was received |
| GET | /goods-receipts | `status, supplier_id, node_id, purchase_order_id, date_from, date_to` |
| GET | /goods-receipts/{id} | includes `type` (DIRECT_PURCHASE / PURCHASE_ORDER) and raw `yield_analysis` |
| POST | /goods-receipts | `{supplier_id, node_id, purchase_order_id?, business_date?, received_at?, notes?, lines: [{product_id, quantity, uom?, unit_cost?, purchase_order_line_id?, dispositions: [{type, quantity, target_product_id?, reason?}]}]}` → DRAFT, number `GR-YYMMDD-NNN`. `unit_cost` required for direct purchases, defaults from the PO line otherwise. Σ dispositions must equal quantity |
| POST | /goods-receipts/{id}/post | `{expected_version?}` — writes the ledger |
| POST | /goods-receipts/{id}/reverse | `{reason, expected_version?}` — compensating movements |
| POST | /goods-receipts/{id}/cancel | `{reason}` — DRAFT only |
| GET | /inventory/current | `product_id, node_id` → available / quarantine / return_pending / on_hand / ledger_on_hand |
| GET | /inventory/balances | `node_id, product_id, bucket, include_zero, page, per_page` (paged since A4) |
| GET | /inventory/movements | `product_id, node_id, bucket, movement_type, source_document_id, correlation_id, date_from, date_to, page, per_page` (newest first) |
| GET | /inventory/integrity | projection vs ledger |
| POST | /inventory/losses | `{node_id, product_id, quantity, uom?, reason}` (from AVAILABLE) |
| POST | /inventory/regrades | `{node_id, product_id, to_product_id, quantity, uom?, reason}` |
| POST | /inventory/recoveries | `{node_id, product_id, quantity, from_bucket: RETURN_PENDING|QUARANTINE, uom?, reason}` → AVAILABLE |
| POST | /inventory/supplier-returns | `{node_id, product_id, quantity, uom?, reason}` (ships RETURN_PENDING back) |
| POST | /inventory/adjustments | `{node_id, product_id, quantity, direction: IN|OUT, uom?, reason}` |

There is no endpoint that sets a stock quantity and no DELETE/PATCH for
documents. Error codes added in A3 include `OVER_RECEIPT`, `PO_NOT_RECEIVABLE`,
`PO_HAS_RECEIPTS`, `MASS_BALANCE_VIOLATED`, `DISPOSITION_NOT_FOR_RECEIPT`,
`REGRADE_TO_SAME_PRODUCT`, `UNIT_COST_REQUIRED`, `UOM_NOT_BASE`,
`SUPPLIER_NOT_USABLE`, `NODE_NOT_USABLE`, `PRODUCT_INACTIVE` (all 422) and
`INVALID_STATUS_TRANSITION`, `INSUFFICIENT_STOCK`, `CONCURRENT_MODIFICATION` (409).

## Sales, payments, receivables (Phase A4) — all authenticated

| Method | Path | Notes |
| --- | --- | --- |
| GET | /sales | `status, payment_status, customer_id, node_id, walk_in, date_from, date_to, q (number / customer), page, per_page` |
| GET | /sales/{id} | lines (price snapshot, returnable qty), totals, paid, credited, outstanding, due date, payments, returns |
| POST | /sales | `{customer_id? (null = walk-in), node_id, business_date?, notes?, lines: [{product_id, quantity, uom?, unit_price, discount?}], confirm?, payments?: [{method, amount, reference?, paid_at?}]}` — number `SAL-YYMMDD-NNN`; with `confirm=true` stock leaves and counter payments are recorded in the same transaction |
| POST | /sales/{id}/confirm | `{payments?, expected_version?}` |
| POST | /sales/{id}/cancel | `{reason, expected_version?}` — DRAFT only |
| POST | /sales/{id}/reverse | `{reason, reverse_payments?, expected_version?}` |
| POST | /sales/{id}/payments | `{method: CASH|BANK_TRANSFER|QRIS, amount, reference?, notes?, paid_at?, business_date?, expected_version?}` → payment + updated sale; number `PAY-…` |
| POST | /sales/{id}/returns | `{reason, refund_method?, lines: [{sale_line_id, quantity, uom?, disposition: RETURN_TO_AVAILABLE|RETURN_TO_QUARANTINE|LOSS, reason?}], expected_version?}` → number `RET-…` |
| GET | /payments · /payments/{id} | `method, status (POSTED|REVERSED), sale_id, customer_id, date_from, date_to` |
| POST | /payments/{id}/reverse | `{reason}` |
| GET | /customer-returns · /customer-returns/{id} | `sale_id, customer_id, date_from, date_to` |
| GET | /receivables | open sales: `customer_id, overdue, due_on_or_before` (days overdue, status) |
| GET | /receivables/customers | per customer: outstanding, overdue, due today, oldest due, credit limit, available credit; `q, overdue` |
| GET | /receivables/summary | total / not yet due / due today / overdue |
| GET | /reports/daily | `date` (default today): sales, payments by method, reversals, refunds, receivables |
| GET | /reports/sales | `date_from, date_to` |
| GET | /reports/sales-by-product | `date_from, date_to`: qty, revenue, returns, avg price + raw purchase figures |

409 codes added: `PAYMENT_EXCEEDS_OUTSTANDING`, `CREDIT_LIMIT_EXCEEDED`,
`RETURN_EXCEEDS_SOLD`, `PAYMENT_ALREADY_REVERSED`, `SALE_HAS_PAYMENTS`,
`SALE_HAS_RETURNS`, `SALE_NOT_PAYABLE`, `SALE_NOT_RETURNABLE`,
`WALK_IN_MUST_BE_PAID`. 422 codes added: `CREDIT_NOT_ALLOWED`,
`CUSTOMER_NOT_USABLE`, `DISCOUNT_EXCEEDS_SUBTOTAL`, `REFUND_METHOD_REQUIRED`,
`RETURN_LINE_DUPLICATE`, `PAYMENT_METHOD_INVALID`.

## Operational readiness & lots (Phase A5-B) — all authenticated unless noted

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/health/ready` | public readiness probe (DB, migrations, APP_KEY, bootstrap, timezone); 503 when not ready |
| GET | `/config` | operational defaults: currency, timezone, business date, default node, walk-in customer, pagination, payment methods |
| GET | `/dashboard?date=` | owner day view: sales, payments by method, receivables as of the date, purchasing, stock (no HPP) |
| GET | `/activity?date=&type=` | the day's events + `summary.receivable_change` |
| GET | `/search?q=&type=` | products, partners (code/name/phone), documents (number prefix) |
| GET | `/lots`, `/lots/{id-or-number}`, `/lots/{id}/movements`, `/lots/{id}/lineage`, `/lots/{id}/destinations` | lot traceability (BATCH_LOT_MODEL.md) |
| GET | `/inventory/lot-balances` | remaining per lot × bucket, FIFO order |
| POST | `/sales/{id}/confirm-with-credit-override` | Idempotency-Key; OWNER permission `sales.override_credit_limit`; `{reason, payments?, expected_version?}` |

All responses carry `X-Request-Id`. Goods-receipt lines accept optional
`manufactured_date` / `best_before_date` (copied to the lot).
