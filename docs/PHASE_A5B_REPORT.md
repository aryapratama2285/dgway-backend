# Phase A5-B report — Operational readiness + inventory batch/lot foundation

Baseline: `dgway-backend-phase-a5-data-evolution.tar.gz` — 445 tests / 18,771
assertions, all green (re-run before any change: `docs/evidence/a5b-baseline-test-run.txt`).
Result: **495 tests / 19,820 assertions, all green** (`docs/evidence/a5b-test-run.txt`).
Labels: IMPLEMENTED / TESTED / PARTIALLY TESTED / NOT TESTED / NOT IMPLEMENTED.

## 1. Architecture decisions

| # | Decision | Why |
| --- | --- | --- |
| D1 | **StockKey unchanged**; lot = extra column `stock_movements.lot_id` + second projection `stock_lot_balances` (lot × node × bucket) | Impact analysis: putting the lot into StockKey would change every balance row, lock, query and A3/A4 test; as a dimension, position semantics, negative-stock policy and locks stay identical |
| D2 | One RECEIPT lot per posted GR line; derived REGRADE lots with `parent_lot_id`; ADJUSTMENT lot for stock-count IN; one LEGACY lot per product × node for pre-A5-B history | Every quantity has exactly one lot; no supplier/receipt is invented for history |
| D3 | Historical movements keep `lot_id NULL`; `dgway_legacy_lot()` resolves them; CHECK `lot_id IS NOT NULL OR created_at < dgway_lot_cutover()` | Append-only history is never rewritten; new rows can never be unattributed |
| D4 | Lot status derived (ACTIVE/DEPLETED), `inventory_lots` append-only | No status to drift; corrections are movements |
| D5 | FIFO (business date, received at, number), server-side; **no FEFO** | Dates are optional → FEFO would be fake; FIFO is deterministic |
| D6 | Allocation: lock positions (StockKey order) → read lot rows FOR UPDATE → post (lot balances locked in key order) | Same position lock as A3 serialises consumers; deadlock-free order |
| D7 | Return policy: attribute to the line's lots in allocation order, each up to consumed − already returned, derived from the ledger | Deterministic across retries/partial returns, no extra state |
| D8 | Credit override = dedicated use case + typed `CreditLimitOverride` value object, re-authorized inside the transaction via an `Authorizer` port | No boolean bypass; no `role == OWNER` in use cases; limit unchanged |
| D9 | Authorization extension point: `Permission` enum + role→permission map in config (future roles listed, empty) | New roles = config + role CHECK widening |
| D10 | `Application\Operations` read layer for dashboard/activity/search/config/bootstrap | Cross-context reads without coupling the bounded contexts |
| D11 | Receivables on the dashboard are rebuilt **as of a date** from dated facts | A past day shows what was owed then, not now |
| D12 | Fingerprint comparison normalized by natural keys; `--allow-additions`; `fingerprint_added` columns hashed separately | Expand-only migrations can be reconciled strictly against older baselines |
| D13 | Deterministic legacy lot id/number; cut-over instant in an IMMUTABLE function | Rollback/re-migrate reproduces the same data; schema catalog identical in every environment |

## 2. Files

New (main): `database/migrations/2026_10_04_100100_create_inventory_lots.php`;
Domain `Inventory/{InventoryLot, LotOrigin, LotRepository, LotBalance, LotBalanceKey, LotStock, LotPortion, FifoAllocator}`, `Sales/CreditLimitOverride`;
Application `Inventory/{LotAllocator, LotRegistry, LotQueries}`, `Sales/{ConfirmSaleWithCreditOverride, SaleLotConsumption}`, `Shared/Security/{Authorizer, Permission, AccessDenied}`, `Operations/{OperationsQueries, OwnerDashboard, BootstrapOperations}`;
Infrastructure `Persistence/Repository/DbLotRepository`, `Persistence/Query/{DbLotQueries, DbSaleLotConsumption, DbOperationsQueries}`, `Security/RoleBasedAuthorizer`;
HTTP `Inventory/LotController`, `Operations/OperationsController`, `Middleware/AssignRequestId`;
Console `dgway:bootstrap`, `dgway:doctor`, `dgway:dev:perf`;
Tests `Feature/Lots/*` (6 files), `Feature/Operations/*` (4), `Feature/Contract/MobileApiContractTest`, `Feature/Sales/CreditLimitOverrideTest`;
Docs `BATCH_LOT_MODEL, API_CONTRACT_V1, API_ERROR_CODES, MOBILE_IDEMPOTENCY, BACKUP_RESTORE, DEPLOYMENT, PHASE_A5B_REPORT` + `docs/evidence/a5b-*`.

Modified: ledger domain (`StockMovement`, `StockPosting`, `StockLedger`, `InventoryOperation`, `InsufficientStock`), `GoodsReceiptLine`, `Sale::confirm`, use cases (`PostGoodsReceipt`, `ReceiptMovementPlanner`, `CreateGoodsReceipt`, `RecordInventoryOperation`, `LedgerReversal`, `ConfirmSale`, `SalesMovementPlanner`), `DbStockLedger`, `DbStockProjection`, `DbGoodsReceiptRepository`, `DataFingerprintCommand`, `TraceCommand`, `HealthController`, `ApiController`, two middlewares, `ApiExceptionRenderer`, routes, providers, `config/{dgway,data_contract}.php`, schema catalog and the docs listed in README.
Modified tests (no test removed; method counts unchanged): `LedgerDatabaseConstraintsTest` (raw rows now name a lot), `InventoryDomainTest` (new `movements()` signature; see §11), `OwnerQueriesTest` (flaky planner assertion made deterministic, see §11), `tests/Support/worker.php` (2 scenarios).

## 3. Migration

`2026_10_04_100100_create_inventory_lots` (expand-only): tables `inventory_lots`
(append-only), `stock_lot_balances`; columns `stock_movements.lot_id` (FK + index
`lot_id, sequence`), `goods_receipt_lines.manufactured_date/best_before_date`;
CHECKs (origin, quantity, provenance, parent, dates, `sm_lot_required_check`,
bucket, non-negative); partial unique indexes (one lot per GR line / per
derived source / per adjustment / per legacy position); functions
`dgway_legacy_lot`, `dgway_lot_cutover`, triggers `dgway_lot_guard`,
`dgway_movement_lot_guard`, deferred `dgway_check_lot_origin_quantity`, lot
clause in `dgway_validate_reversal`; backfill of LEGACY lots + lot projection.
`down()` = development only (restores the A3 reversal function verbatim).
IMPLEMENTED · TESTED (fresh migrate, dev round-trip, automated real rollback test).

## 4. Lot model

See docs/BATCH_LOT_MODEL.md. Example (smoke): GR 150 kg → LOT-261003-001,
original 150 = RECEIPT 140 AVAILABLE + RECEIPT 10 QUARANTINE − LOSS 10;
retail 30 + B2B 50 allocated FIFO from that lot; remaining 60.
IMPLEMENTED · TESTED.

## 5. API changes (all additive)

`GET /config`, `/dashboard`, `/activity`, `/search`, `/health/ready`, `/lots`,
`/lots/{id|number}`, `/lots/{id}/movements|lineage|destinations`,
`/inventory/lot-balances`, `POST /sales/{id}/confirm-with-credit-override`;
GR lines accept `manufactured_date`/`best_before_date`; every response has
`X-Request-Id`; new codes `ACCESS_DENIED`, `CREDIT_OVERRIDE_NOT_REQUIRED`,
`CREDIT_OVERRIDE_REASON_REQUIRED`, `LOT_DATES_INVALID` (+ internal lot guards).
No existing endpoint changed shape. Contract: docs/API_CONTRACT_V1.md. IMPLEMENTED · TESTED.

## 6. Concurrency (real PostgreSQL, separate OS processes)

| Scenario | Result | Test |
| --- | --- | --- |
| Lot A remaining 50 (+ lot B 100), two concurrent 40 kg sales | second waited (>500 ms); allocations A40 and A10+B30; A = 0, B = 70 | `LotMultiProcessTest` |
| Only lot A (50), two 40 kg sales | second 409 INSUFFICIENT_STOCK, sale stays DRAFT, no payment, A = 10 | idem |
| 7 processes × 15 kg over 3 lots × 30 | exactly 6 ok + 1 INSUFFICIENT_STOCK, all lots 0, no negative lot balance | idem |
| Same payment reversed twice at once (Part 23) | 1 success, 1 × 409 PAYMENT_ALREADY_REVERSED, 1 reversal row, outstanding 1,275,000 | idem |
| Two credit overrides for one customer | serialized on the credit lock; audits show exposure 0→1.2M and 1.2M→2.3M | `CreditLimitOverrideTest` |

TESTED.

## 7. Idempotency

GR post retry → replay, one lot, no burned LOT number; sale retry → replay, no
second allocation; return retry → no extra CUSTOMER_RETURN; credit-override
retry → one audit entry; new key on a posted GR → 409, no lot. Audit rows carry
`idempotency_key`, `command`, `request_id`. Client rules: docs/MOBILE_IDEMPOTENCY.md. TESTED.

## 8. Tests

495 tests / 19,820 assertions (baseline 445 / 18,771; +50 new). Part 26 mapping:

| # | Requirement | Test |
| --- | --- | --- |
| 1, 2 | lot created exactly once; retry no duplicate | `LotLifecycleTest` |
| 3, 4 | identity immutable; no hard delete | `LotDatabaseConstraintsTest` |
| 5 | disposition quantities reconcile | `LotLifecycleTest` (150 = 140 + 10), origin-quantity trigger in `LotDatabaseConstraintsTest`, `LotReconciliationTest` |
| 6 | regrade lineage | `LotLifecycleTest` (receipt + operation), `LotQueryApiTest` (lineage API) |
| 7, 8 | FIFO one / multiple lots | `LotLifecycleTest` |
| 9 | concurrent sale cannot over-consume | `LotMultiProcessTest` |
| 10 | sale retry no duplicate allocation | `LotLifecycleTest` |
| 11 | return preserves lot lineage | `LotLifecycleTest` |
| 12 | product mismatch rejected | `LotDatabaseConstraintsTest` |
| 13 | trace lot end-to-end | `LotQueryApiTest::test_trace_follows_a_lot…` |
| 14 | lot fingerprint reconciliation | `LotReconciliationTest::test_lot_invariants_reconcile…` |
| 15 | rollback/re-migrate fingerprint | `LotReconciliationTest::test_rollback_and_remigrate…` (real migration over pre-A5-B rows) |
| 16 | concurrent payment reversal | `LotMultiProcessTest::test_concurrent_reversal_of_the_same_payment` |
| 17 | owner override authorization | `CreditLimitOverrideTest`, `MobileApiContractTest` |
| 18 | concurrent override | `CreditLimitOverrideTest::test_concurrent_overrides…` |
| 19 | Asia/Jakarta midnight boundary | `OperationsApiTest::test_asia_jakarta_midnight_boundary` |
| 20 | API contract, core mobile flow | `MobileApiContractTest` (4 tests incl. "every error code documented") |

## 9. Smoke evidence (live HTTP, fresh DB)

`docs/evidence/a5b-smoke-test.sh` → `a5b-smoke-test-output.txt`: readiness
ready; `/config` gives node + walk-in (no hard-coded UUID); PO-261003-001
3,450,000; GR posted (retry replayed, 1 lot); LOT-261003-001 original 150,
remaining 140; retail SAL-261003-001 810,000 COMPLETED, stock 110; B2B
SAL-261003-002 1,275,000 paid 775,000 outstanding 500,000 due 2026-10-10,
retry replayed, stock 60; lot movements RECEIPT 140 / RECEIPT 10 / LOSS −10 /
SALE −30 / SALE −50; destinations walk-in 30, Catering ABC 50; receivable
500,000; dashboard sales 2 / 2,085,000, payments 1,585,000 (cash 810,000,
transfer 775,000), PO 3,450,000, received 150 (140/10), stock 60, loss 10;
activity + receivable change 0 → 500,000; search by phone `0812-3333` and by
lot number; audit actions listed, 21/21 operator audit rows carry request_id;
`dgway:trace LOT-261003-001`; inventory verify consistent.

## 10. Migration / fingerprint evidence

- Dev DB with A4 history (`a5b-migration-roundtrip.txt`): rolled back = identical to pre-A5-B baseline; up → `--allow-additions` identical (additions only: `document_sequences.LOT`, `lots`, `inventory_lots`, `stock_movements+added`, `goods_receipt_lines+added`); verify OK; down → strict identical; re-up → strict identical to first up; verify OK.
- Fresh migrate from zero: `a5b-fresh-migrate.txt` (+ smoke on that DB).
- Backup/restore drill: `a5b-backup-restore-drill.txt` (dump → restore → nothing to migrate → fingerprint identical → verify → guards active → doctor OK).
- Performance (1000 products, 1001 partners, 1200 lots, 3000 sales, 9.5k movements; `a5b-performance.md`): all lot/inventory/search lists 2–4 SQL statements regardless of page size, 2–21 ms; dashboard 18 statements ≈ 19 ms; activity ≈ 23 ms. No index added beyond the migration's own (`stock_movements(lot_id, sequence)`, `stock_lot_balances(product_id, node_id, bucket)`, `inventory_lots(product_id, node_id, business_date)`, supplier, GR, parent, business_date): the plans use them and nothing else was slow.

## 11. Defects found / changes to earlier tests (disclosed)

1. **A4 flaky test** `OwnerQueriesTest::test_receivable_queries_use_the_open_sales_index`: failed only in the full suite (planner chose the other equal-cost partial index on a near-empty table; passes alone). Fixed in the test by hiding the competing index inside a rolled-back transaction; intent unchanged. Not a product defect.
2. `InventoryDomainTest`: `InventoryOperation::movements()` now takes lot portions; two tests adapted (and strengthened with lot assertions). The A3 assertion "same quantity in the same unit" was replaced by "Lot portions total"; the unit rule itself is enforced by `MasterDataGuard::assertBaseUom` (unchanged since A3) and now has a feature test (`LotLifecycleTest::test_regrade_to_a_product_with_another_base_uom_is_refused…`).
3. During A5-B: the first rewrite of `dgway_validate_reversal` was not faithful to A3 (caught by `LedgerDatabaseConstraintsTest`, fixed to the exact original + lot clause); the CHECK with a literal cut-over made the schema catalog environment-specific (fixed with `dgway_lot_cutover()`); legacy lots were random on re-migrate (made deterministic).

## 12. Limitations

- FIFO only, no FEFO/expiry/blocked lots; dates optional.
- TRUNCATE is not guarded by triggers (same as every A1–A4 append-only table); mitigated by DB privileges (DEPLOYMENT.md §3).
- `down()` after real post-cut-over activity loses lot attribution (dev only; production never rolls back).
- Dashboard `inventory.active_lots_now` is current (lot projection is not dated); everything else is as of the date. Activity `PURCHASE_ORDER.occurred_at` = PO creation time (no confirmed_at column).
- Search uses `LIKE` (no trigram index); fine at measured volume, revisit at ~100k partners/products.
- Authorization: only OWNER exists; future roles are configuration placeholders.
- `dgway:dev:perf` seeds into the current database — run it only on a scratch DB.
- Live smoke ran on `php artisan serve`, not Nginx/PHP-FPM.

## 13. Not implemented (out of scope)

Printing/scanning/QR/barcode (A5-C), Flutter app, orders/delivery/driver,
marketplace / supplier portal / Project B / Java, payment gateway, GL/tax/HPP,
promotions, AI forecasting, Kafka/microservices/Kubernetes, unit-level
serialisation, FEFO, transfers between nodes, additional roles.
