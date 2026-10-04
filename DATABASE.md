# Database — implemented through Phase A4

PostgreSQL 16, Laravel migrations only (no manual schema changes).

## Conventions (apply to every later table)

| Concern | Rule |
| --- | --- |
| Primary keys | `uuid` (Sanctum token ids excepted) |
| Money | `NUMERIC(19,4)` |
| Quantity | `NUMERIC(18,4)` |
| Timestamps | `timestamptz`, stored in UTC (connection time zone forced to UTC) |
| Business day | `business_date date` (Asia/Jakarta) on transaction tables |
| Ledgers | append-only, protected by `dgway_forbid_mutation()` trigger |
| Enumerations | `varchar` + `CHECK` constraint (widen the check to add values) |
| Deletes | final documents are never deleted; status / reversal instead |

## Tables

| Table | Purpose | Key constraints |
| --- | --- | --- |
| `users` | operator accounts | uuid PK, unique email, `CHECK role IN ('OWNER')`, `CHECK email = lower(email)` |
| `personal_access_tokens` | Sanctum tokens | unique token hash, `uuidMorphs`, `expires_at` |
| `audit_logs` | append-only audit trail | FK actor → users, indexes on entity / actor+time / business_date / action, **UPDATE/DELETE trigger-blocked** |
| `idempotency_keys` | committed idempotent commands | `UNIQUE (actor_scope, idempotency_key)`, status check, completed-row check; `response_body json` (verbatim, since A2) |
| `document_sequences` | per-prefix per-day counters | PK `(prefix, sequence_date)`, `CHECK last_value >= 1` |
| `uoms` | units of measure reference data | unique code, `CHECK precision BETWEEN 0 AND 4`, code format check; seeded KG(3) PCS(0) IKAT(0) |
| `product_categories` | category tree | unique code + format check, self-FK (RESTRICT), `parent_id <> id`, version ≥ 1 |
| `products` | one row per SKU | unique SKU + format/upper-case check, FK category (RESTRICT), FK `base_uom_code → uoms.code`, description ≤ 1000, version ≥ 1 |
| `business_partners` | partner identity + embedded address | unique code, party_type check, phone E.164 check, email lower-case, address checks (street required if any part, 5-digit postal code, lat/long pair + range) |
| `business_partner_roles` | SUPPLIER / CUSTOMER role per partner | `UNIQUE(partner_id, role)`, role check, customer_type only and always for CUSTOMER, supplier has no credit limit, WALK_IN is cash only, term 0–365, credit ≥ 0, FK partner (RESTRICT) |
| `locations` | physical places + embedded address | unique code, address checks |
| `nodes` | operational units | unique code, `capabilities text[]` non-empty subset of the 5 capabilities, FK location (RESTRICT, nullable) |
| `purchase_orders` | optional PO header | unique number, status check, `cancel_reason` iff CANCELLED, version ≥ 1, **no DELETE** (trigger) |
| `purchase_order_lines` | ordered product, qty, cost | `UNIQUE(po, line_no)`, `UNIQUE(po, product)`, `0 ≤ received ≤ ordered`, `line_total = round(qty × cost, 4)`, terms immutable (trigger: only `received_quantity` may change), no DELETE |
| `goods_receipts` | receipt header (direct or against a PO) | unique number, status check, `posted_at`/`reversed_at` consistent with status, trigger: legal transitions only, posted header immutable, **mass balance checked when becoming POSTED**, no DELETE |
| `goods_receipt_lines` | received qty + unit cost | `received > 0`, `line_cost = round(qty × cost, 4)`, unique product per receipt; insert only while DRAFT, never updated/deleted (trigger) |
| `goods_receipt_dispositions` | ACCEPT / LOSS / RETURN_TO_SUPPLIER / REGRADE | type check, qty > 0, `target_product_id` iff REGRADE; same insert-only trigger |
| `inventory_operations` | INV-numbered stock operations (loss, regrade, recovery, supplier return, adjustment) | type/bucket checks, reason required, target iff REGRADE, direction iff ADJUSTMENT, **append-only** |
| `stock_movements` | **the ledger** | see docs/INVENTORY_LEDGER.md: type/bucket/direction CHECKs, type↔direction CHECK, qty > 0, generated `signed_quantity`, `UNIQUE reverses_movement_id`, REVERSAL iff link, reversal-mirror trigger, **append-only** |
| `stock_balances` | projection per (product, node, bucket) | PK on the position, `quantity >= 0`, bucket check |
| `inventory_lots` (A5-B) | lot identity + provenance (RECEIPT / REGRADE / ADJUSTMENT / LEGACY) | unique LOT number, origin/quantity/provenance/parent/date CHECKs, one lot per GR line / per derived source / per adjustment / per legacy position (partial unique indexes), provenance = GR line (trigger), deferred Σ creating movements = original quantity, **append-only** |
| `stock_lot_balances` (A5-B) | projection per (lot, node, bucket) | PK on (lot, node, bucket), `quantity >= 0`, bucket check; rebuilt/verified with `stock_balances` |
| `stock_movements.lot_id` (A5-B) | lot dimension of the ledger | FK, index (lot_id, sequence); required after the cut-over (`sm_lot_required_check` with `dgway_lot_cutover()`); lot product/uom = movement's; creating movements only into a lot of the same source (trigger); reversal mirrors the lot |
| `sales` | sale header | unique number, status/origin checks, `grand_total = subtotal − discount_total ≥ 0`, generated `outstanding` (`CHECK ≥ 0`), money only on final sales, COMPLETED ⇔ outstanding 0, walk-in never owes, confirmation fields consistent, `due_date ≥ business_date`; trigger: status machine, final header immutable, totals = Σ lines at confirm, no delete; partial indexes on open sales |
| `sale_lines` | product, qty, unit price snapshot, discount | `qty > 0`, `line_subtotal = round(qty × price, 4)`, `0 ≤ discount ≤ subtotal`, `line_total = subtotal − discount`, `0 ≤ returned ≤ qty`; trigger: insert only while DRAFT, terms immutable, return counters only grow, no delete |
| `payments` | money received | `amount > 0`, method check, **append-only** |
| `payment_reversals` | reversal of a payment | `UNIQUE(payment_id)`, reason required, **append-only** |
| `customer_returns` | RET document | `total_value = credit + refund`, refund method iff refund, reason required, **append-only** |
| `customer_return_lines` | returned qty per sale line | qty > 0, disposition check, **append-only** |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel infrastructure (rate limiter cache, queue) | — |

## Functions / triggers

- `dgway_forbid_mutation()` — raises `restrict_violation` on UPDATE/DELETE.
- `audit_logs_append_only` — `BEFORE UPDATE OR DELETE` trigger on `audit_logs`.
- A3: `stock_movements_append_only`, `inventory_operations_append_only`
  (reuse `dgway_forbid_mutation`); `dgway_validate_reversal` (reversal mirrors
  its original, no reversal of a reversal); `dgway_goods_receipt_guard`
  (status machine, posted header immutable, mass balance at POSTED, no
  delete); `dgway_receipt_detail_guard` (lines/dispositions insert-only while
  DRAFT); `dgway_po_line_guard` (terms immutable, no delete);
  `purchase_orders_no_delete`.
- A4: `dgway_sale_guard`, `dgway_sale_line_guard`, append-only triggers on
  payments / payment_reversals / customer_returns(+lines), and the DEFERRED
  constraint trigger `dgway_check_sale_money` (cached `paid_amount`,
  `credited_amount`, `returned_*` must equal the append-only history at
  COMMIT).
- A5 (data evolution): `dgway_guard_identity` on all master tables and
  `users` — rows are never deleted, UUID and business key (sku/code,
  partner+role) are immutable. Every table is declared in
  `config/data_contract.php`; full generated catalog: `docs/SCHEMA_CATALOG.md`.
- Every FK to `products.id` outside master data is listed in
  `config/dgway.php → product_reference_tables` (used to lock a product's
  base UOM once it has transactions); a test fails if one is missing.

## Master-data conventions (A2)

- No hard deletes: every FK from master data uses `ON DELETE RESTRICT`;
  records are deactivated (`is_active = false`) and stay referenceable.
- `version` column on every aggregate table for optimistic concurrency.
- Codes are unique by database constraint; the application never does
  "SELECT then INSERT" to enforce uniqueness.
- Address columns (`address_*`) and their constraints come from one helper
  (`MasterDataSchema`) instead of being copied per table.

## ERD (A2)

```
product_categories ─┐ parent_id (self)
        │ 1         │
        │ *         │
     products ── base_uom_code ──> uoms.code

business_partners 1 ── * business_partner_roles   (max one SUPPLIER + one CUSTOMER)

locations 1 ── * nodes   (nodes.location_id nullable)
```

## ERD (A3)

```
business_partners (supplier) 1 ── * purchase_orders 1 ── * purchase_order_lines ── product
nodes 1 ── * purchase_orders / goods_receipts / inventory_operations
purchase_orders 1 ── * goods_receipts (nullable = direct purchase)
goods_receipts 1 ── * goods_receipt_lines 1 ── * goods_receipt_dispositions (target_product for REGRADE)
goods_receipt_lines * ── 0..1 purchase_order_lines

stock_movements ── product, node, bucket   (source_document_type/id → goods_receipt | inventory_operation)
stock_movements 0..1 ── reverses ──> stock_movements
stock_balances  = Σ stock_movements.signed_quantity per (product, node, bucket)
```

## ERD (A4)

```
business_partners (customer, nullable = walk-in) 1 ── * sales ── node
sales 1 ── * sale_lines ── product
sales 1 ── * payments 1 ── 0..1 payment_reversals
sales 1 ── * customer_returns 1 ── * customer_return_lines ── sale_lines
stock_movements.source_document_type = 'sale' | 'customer_return' (+ REVERSAL rows)
```

## ERD (A5-B)

```
goods_receipt_lines 1 ── 0..1 inventory_lots (origin RECEIPT) ── supplier, node, product
inventory_lots 1 ── * inventory_lots (parent_lot_id, origin REGRADE)
inventory_lots 1 ── * stock_movements (lot_id; NULL = pre-A5-B history → dgway_legacy_lot(product, node))
inventory_lots 1 ── * stock_lot_balances (per node × bucket)
stock_movements(SALE, lot) ── sale_lines ── sales ── customer   (lot destinations)
```

Full generated detail: docs/SCHEMA_CATALOG.md.

## A5-C

| Table / column | Purpose | Rules |
| --- | --- | --- |
| `goods_receipt_lines.collection_date`, `supplier_reference` | optional upstream facts stated by the supplier | `grl_upstream_check`: reference not blank; collection ≥ manufactured |
| `inventory_lots.collection_date`, `supplier_reference` | copied from the receipt line (RECEIPT) / parent (REGRADE) | `lot_upstream_check`: collection ≤ business_date (DG Way received), ≥ manufactured; only RECEIPT/REGRADE may carry them; `dgway_lot_guard`: must equal the source; append-only |
| `print_settings` | business identity printed on documents (one row max) | unique index on a constant, width ∈ {58, 80}, name not blank, identity guard (no delete) |
