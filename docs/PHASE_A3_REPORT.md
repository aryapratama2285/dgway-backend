# Phase A3 — Procurement, Receiving & Inventory Ledger · Verification Report

Date: 2026-10-03 · PHP 8.3.6 · PostgreSQL 16.15 · Laravel 13.34.0 · PHPUnit 12.5.37
Baseline: `dgway-backend-phase-a2.tar.gz` (no new project; A1/A2 architecture kept).

Status vocabulary: IMPLEMENTED · TESTED · PARTIALLY TESTED · NOT TESTED · NOT IMPLEMENTED

## Results

- Full suite: **339 tests, 11 798 assertions, 0 failures** (Unit 170 · Feature 156 · Architecture 13)
  — `docs/evidence/a3-test-run.txt` (A2 baseline was 232).
- Migrations: fresh → rollback of the 2 A3 migrations (A1/A2 tables intact) →
  re-migrate → full reset → migrate from zero — `docs/evidence/a3-migrations.txt`.
- Live HTTP smoke test (Scenarios A, B, C) — `docs/evidence/a3-smoke-test.sh`,
  `a3-smoke-test-output.txt`.
- Negative check for the stock lock — `docs/evidence/a3-negative-lock-check.txt`.

## Component status

| Component | Status |
| --- | --- |
| PurchaseOrder state machine (domain + DB), optional PO, confirm/cancel | TESTED |
| Direct purchase and PO-based receipt through the same ledger | TESTED |
| Partial receiving, over-receipt rejected (create and post time) | TESTED |
| GoodsReceipt numbering (GR), immutability after posting (domain + triggers) | TESTED |
| Dispositions ACCEPT / LOSS / RETURN_TO_SUPPLIER / REGRADE; RECOVERY as operation | TESTED |
| Mass balance (domain property test, DB trigger at POSTED, ledger Σ RECEIPT) | TESTED |
| REGRADE OUT/IN pair with one correlation id (receipt and operation) | TESTED |
| Ledger append-only (app has no mutation path; DB trigger) | TESTED (incl. raw SQL and live psql) |
| Balance projection in the same transaction; verify; rebuild | TESTED |
| No negative stock, concurrency-safe | TESTED (multi-process + negative check) |
| Money/decimal costs, line total CHECK | TESTED |
| Raw yield data, no HPP policy | IMPLEMENTED (exposed, tested; no valuation by design) |
| Reversal via compensating movements, never twice, blocked if stock used | TESTED (incl. concurrent reversal) |
| Idempotency on all 11 new mutating endpoints | TESTED (create/post/loss directly; every route is in the `idempotency` group) |
| Stale `expected_version` → 409 | TESTED (PO confirm); IMPLEMENTED for post/reverse/cancel |
| Audit events (12 required + `inventory.reversal`) | TESTED (each action asserted in a feature test) |
| `business_date` / `occurred_at` / `created_at` distinct | TESTED |
| Master-data validation for new transactions; history readable after deactivation | TESTED |
| Base-UOM policy (`UOM_NOT_BASE`, no conversion) | TESTED |
| Context isolation (Procurement ↔ Inventory ↔ master data) | TESTED (architecture rules + sanity violation check) |
| Console: `dgway:inventory:verify`, `dgway:inventory:rebuild-balances` | TESTED |
| SALE, transfers, customer returns | NOT IMPLEMENTED (types declared only; A4+) |
| Cost on inventory operations / valuation | NOT IMPLEMENTED (by design) |

## Defects found in earlier phases

1. **A2 — category subtree depth** (required hardening): moving a category
   checked only the moved node's own depth, not its deepest descendant, so a
   subtree could exceed the 5-level limit. Failing test first
   (`CategorySubtreeDepthTest`, returned 200 instead of 422), fixed with
   `subtreeHeight()` (recursive CTE) + `ProductCategory::moveUnder(…, $subtreeHeight)`.
2. **A2 — product reference registry shape**: `product_reference_tables` was a
   `table => column` map, which cannot express two product columns in one
   table (`inventory_operations.product_id` and `.target_product_id`). Changed
   to a list of `table.column`; a new test compares it with every FK to
   `products.id` in PostgreSQL.

## Known limitations / notes

- Removing only `FOR UPDATE` was not detected by the tests, because the
  preceding `INSERT … ON CONFLICT DO NOTHING` also waits for in-flight writers
  in PostgreSQL. Removing the row locking altogether is detected (lost update
  and overdraw tests fail). `FOR UPDATE` is kept as the explicit guarantee.
- All inventory operations share the `INV` daily sequence row, so they
  serialise on it — correct, but a throughput ceiling to watch in A4.
- Receipt lines/dispositions are insert-only even in DRAFT; correcting a
  draft means cancelling it and creating a new one.
- Documents keep ids, not descriptive snapshots (master data is never deleted
  and codes are immutable).
