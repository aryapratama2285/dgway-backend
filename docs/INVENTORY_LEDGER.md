# Inventory ledger (Phase A3)

> **A5-B:** every movement now also names its **lot** (`lot_id`) and a second
> projection `stock_lot_balances` (lot × node × bucket) is maintained in the
> same transaction. The position model below (StockKey, buckets, locks,
> reversal) is unchanged. Lots: docs/BATCH_LOT_MODEL.md.

The ledger (`stock_movements`) is the **only source of truth** for stock.
Nothing in the system sets a stock quantity: every change is a movement
appended by a business document (goods receipt, inventory operation, later
sales). `stock_balances` is a projection that can be thrown away and rebuilt.

## 1. Movement record

| Column | Meaning |
| --- | --- |
| `id` (UUIDv7, PK), `sequence` (bigserial, unique) | identity; `sequence` gives a total order for replay / audit |
| `movement_type` | RECEIPT, SALE, CUSTOMER_RETURN, SUPPLIER_RETURN, TRANSFER_OUT/IN*, LOSS, RECOVERY, REGRADE_OUT/IN, ADJUSTMENT_IN/OUT, REVERSAL (*declared, not used yet). SALE and CUSTOMER_RETURN are used since A4 (docs/SALES_AND_RECEIVABLES.md) |
| `product_id`, `node_id`, `bucket` | the stock position |
| `direction` (+1 / −1), `quantity` (> 0) | see §2 |
| `signed_quantity` | `GENERATED ALWAYS AS (quantity * direction)` — cannot be written |
| `uom_code` | always the product's base UOM |
| `unit_cost`, `currency` | purchase cost carried on receipt movements (nullable for operations) |
| `business_date` / `occurred_at` / `created_at` | operational date (Asia/Jakarta) / when it physically happened / when the row was written |
| `source_document_type`, `source_document_id`, `source_line_id` | the document that caused it |
| `correlation_id` | groups the movements of one posting (e.g. REGRADE_OUT + REGRADE_IN) |
| `reverses_movement_id` (UNIQUE) | for REVERSAL rows: the movement being compensated |
| `actor_user_id`, `reason` | who / why |

### Movement type ↔ direction (DB CHECK `sm_type_direction_check`)

IN only: RECEIPT, CUSTOMER_RETURN, TRANSFER_IN, REGRADE_IN, ADJUSTMENT_IN ·
OUT only: SALE, SUPPLIER_RETURN, TRANSFER_OUT, LOSS, REGRADE_OUT, ADJUSTMENT_OUT, INTERNAL_CONSUMPTION (F3) ·
both: RECOVERY (out of a non-sellable bucket, into AVAILABLE), REVERSAL (mirror of its original).
Adding a type = one enum case + one migration extending the CHECK.

## 2. Why direction + positive quantity (not a free signed quantity)

- A row like `LOSS +5` or `RECEIPT −5` is a contradiction. With a separate
  `direction` the database can **reject** it (type ↔ direction CHECK);
  with a single signed number it cannot tell a typo from intent.
- Quantities are always positive in documents and in the UI ("hilang 5 kg"),
  so the movement stores what humans record.
- Arithmetic is still trivial: `signed_quantity` is a generated column, so
  `SUM(signed_quantity)` is the on-hand figure and nobody can write it wrong.

## 3. Buckets

| Bucket | Sellable | Used for |
| --- | --- | --- |
| AVAILABLE | yes | stock that can be sold (A4) |
| QUARANTINE | no | transit point for rejected receipt quantity (LOSS / REGRADE) — normally 0 after posting |
| RETURN_PENDING | no | goods waiting to go back to the supplier (not a loss) |

Evaluation: three buckets cover A3 without over-engineering. Expiry/lot
tracking, "damaged but sellable at discount", or reserved stock (A4 orders)
would be new buckets or a reservation table later; the bucket is part of the
position key, so adding one is a CHECK + enum change, no redesign.

## 4. Posting a goods receipt

Every received quantity first enters the ledger as RECEIPT, so
**Σ RECEIPT = received quantity** holds in the ledger itself, not only on the
document:

| Disposition | Movements (same `correlation_id` = receipt id) |
| --- | --- |
| ACCEPT q | RECEIPT +q AVAILABLE |
| LOSS q | RECEIPT +q QUARANTINE → LOSS −q QUARANTINE |
| RETURN_TO_SUPPLIER q | RECEIPT +q RETURN_PENDING (later: SUPPLIER_RETURN −q when shipped, or RECOVERY → AVAILABLE) |
| REGRADE q → product B | RECEIPT +q QUARANTINE → REGRADE_OUT −q QUARANTINE → REGRADE_IN +q AVAILABLE (product B) |

Mass balance (received = Σ dispositions) is enforced three times: in the
domain (`GoodsReceiptLine`), by the DB trigger when a receipt becomes POSTED,
and implicitly by the ledger plan above.

RETURN_TO_SUPPLIER is **not** a loss: it produces no LOSS movement and no
`inventory.loss` audit event.

## 5. Balance projection

- `stock_balances (product_id, node_id, bucket) PK, quantity CHECK (>= 0)`.
- Updated **in the same transaction** as the movements (no queue, no lag):
  `StockPosting` locks positions → applies each movement (domain rule:
  `DisallowNegativeStock`) → appends movements → saves balances.
- If anything fails, the whole document, its movements, balances, audit and
  idempotency key roll back together.

### Concurrency

`DbStockLedger::lockBalances()`:

1. sorts the position keys (same lock order everywhere → no deadlock between
   two multi-position postings; a residual deadlock is retried by the
   transaction manager, SQLSTATE 40P01);
2. `INSERT … ON CONFLICT DO NOTHING` so every position row exists;
3. `SELECT … FOR UPDATE` each row, then decides on the **locked** value.

So "two phones both see 5 kg and both take 4 kg" cannot happen: the second
waits, then sees 1 kg and gets `409 INSUFFICIENT_STOCK`. Proven by
multi-process tests; a negative check (locks removed) makes them fail
(`docs/evidence/a3-negative-lock-check.txt`). The DB CHECK `quantity >= 0` is
the last line of defence.

### Rebuild vs concurrent postings (verified in A4)

`rebuild()` takes `LOCK TABLE stock_balances IN EXCLUSIVE MODE` and
`LOCK TABLE stock_movements IN SHARE MODE` inside one transaction:
- a posting already in flight holds row locks on `stock_balances` → the
  rebuild waits until it commits, then includes its movements;
- a posting that starts during a rebuild blocks on its first touch of
  `stock_balances` → it waits, then applies on top of the rebuilt figures.
Both orders are proven by multi-process tests
(`test_rebuild_waits_for_an_in_flight_posting`,
`test_posting_waits_for_an_in_flight_rebuild`). Postings always touch
`stock_balances` before `stock_movements`, the same order as the rebuild, so
the two cannot deadlock; no advisory lock is needed.

### Verify / rebuild

- `GET /api/v1/inventory/integrity` or `php artisan dgway:inventory:verify`
  compares every position with `SUM(signed_quantity)` (exit code 1 on drift).
- `php artisan dgway:inventory:rebuild-balances` locks `stock_balances`,
  recomputes everything from the ledger in one transaction; postings wait and
  continue afterwards. The ledger is never modified.

## 6. Reversal

A posted receipt is immutable. `POST /goods-receipts/{id}/reverse`:

- appends one REVERSAL per original movement (opposite direction, same
  position and quantity — checked by trigger `dgway_validate_reversal`),
  newest first, under a new correlation id;
- `reverses_movement_id` is UNIQUE → a movement can never be reversed twice;
  a reversal cannot itself be reversed (domain + trigger);
- if the stock was already used, the reversal would go negative →
  `409 INSUFFICIENT_STOCK`, nothing written;
- the purchase order's received quantity is reduced (PO goes back to
  CONFIRMED / PARTIALLY_RECEIVED). The correct receipt is then posted as a new
  document.

## 7. Append-only, enforced twice

- Application: no repository method updates or deletes a movement; there is
  no endpoint that sets stock.
- Database: trigger `stock_movements_append_only` (`dgway_forbid_mutation`)
  rejects every UPDATE/DELETE, including from `psql`. Same for
  `inventory_operations`. Tests and the live smoke test (Scenario C) prove it.

## 8. Cost and HPP

- Line total = `round(quantity × unit_cost, 4)` with `brick/math`, checked
  again by a DB CHECK (`150 × 23 000 = 3 450 000.0000`).
- Receipt movements carry the line unit cost. The receipt keeps raw yield
  figures (`received, accepted, loss, returned_to_supplier, regraded,
  purchase_cost`); `cost_per_accepted_unit` is shown as an analytical figure
  only. **No HPP / valuation policy is applied in A3** — that is decided in
  the reporting phase from these raw facts.
- Inventory operations (loss, regrade, adjustment …) carry no cost in A3.

## 9. UOM policy

The ledger is always in the product's **base UOM**. A request in another unit
is rejected with `UOM_NOT_BASE` (no implicit conversion). Regrade targets must
have the same base UOM. Product-specific conversions (e.g. tray → kg) are a
future extension; when added, conversion happens before the ledger.


## 10. F3 — internal consumption, stock opname, reorder points

- **INTERNAL_CONSUMPTION** (operation and movement type, OUT only, from AVAILABLE):
  DG Way used its own material (plastic, labels, thermal paper, tape, raffia,
  stretch film, disposable trays …). It is not a LOSS (damage) and not an
  ADJUSTMENT (correction); the dashboard's `losses_on_date` is unaffected.
  `reason_code` ∈ PACKING, LABELING, DELIVERY, ADMIN, SAMPLE, OTHER (required, DB CHECK),
  optional notes (stored as `reason`) and `reference_text` (≤ 60, operational, no FK).
  Lots are consumed FIFO by `LotAllocator` under the position lock, one movement per lot.
- **Counted adjustment (stock opname):** `counted_quantity` → the server locks the
  AVAILABLE position (`StockLedger::lockBalances`), reads the system quantity, posts
  `counted − system` as ADJUSTMENT_IN (new ADJUSTMENT lot) or ADJUSTMENT_OUT (FIFO),
  and stores `counted_quantity` + `system_quantity` on the operation (DB CHECK: both or neither,
  ADJUSTMENT only). `expected_available` guards against a count made on stale figures
  (409 `STOCK_CHANGED_SINCE_COUNT`). Adjustments need the `inventory.adjust` permission.
- **Reorder points** (`reorder_points`, product × node): configuration, not stock.
  `min_quantity` in the product's base UOM (the unit is derived, never stored; the
  base UOM cannot change while an active threshold exists — application check + trigger
  `products_base_uom_reorder_guard`). Low stock = AVAILABLE ≤ min_quantity; null = disabled.
- Packaging / consumables are ordinary inventory: receipts, lots, consumption, loss,
  counted adjustment and history work exactly like for sellable goods. Classification
  (SELLABLE / PACKAGING / CONSUMABLE) is context, never a permission. Reusable equipment
  (baskets, scales, racks, printers, reusable trays, candling lamps) is NOT inventory:
  it belongs to a future Asset Management domain. Borderline items follow their economic
  behaviour (disposable cardboard tray = PACKAGING; reusable plastic tray = asset).

### Costing traceability (for a future HPP / fulfillment-cost engine — not implemented)

consumption movement → `stock_movements.lot_id` → `inventory_lots.goods_receipt_line_id`
→ `goods_receipt_lines.unit_cost` (+ `goods_receipts.currency`; RECEIPT movements also carry
`unit_cost`/`currency`). The quantity, base UOM, node, business date, actor, reason code,
notes, INV number and correlation id are stored on every consumption. Nothing is copied or
estimated: lots with origin ADJUSTMENT or LEGACY have **no** cost link and must be reported
as *cost basis unknown*. Purchased stock with a known cost enters through receiving, never
through an adjustment. Every row is append-only, so a later engine reads history and never
rewrites it.
