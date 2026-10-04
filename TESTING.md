# Testing

```bash
php vendor/bin/phpunit                     # everything
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit --testsuite Feature
php vendor/bin/phpunit --testsuite Architecture
```

Feature tests require the PostgreSQL test database (`dgway_test`, see
SETUP.md). They do **not** run on SQLite: constraints, triggers, `UPSERT`,
row locks and time zones are PostgreSQL behaviour that must be tested on
PostgreSQL.

## Suites

| Suite | What it proves | DB |
| --- | --- | --- |
| Unit / Domain / Shared | Decimal, Money, Quantity, Uom, BusinessDate, DocumentNumber, Actor rules | no |
| Unit / Application | Idempotency algorithm with in-memory fakes | no |
| Unit / Domain / Catalog, Partner, Network | aggregate behaviour and invariants | no |
| Architecture | Layer dependency rule; bounded contexts independent; controllers do not touch the DB | no |
| Feature / MasterData | API behaviour, idempotent retries, duplicates, optimistic concurrency, audit, deactivation keeps history, DB constraints bypassing the app | PostgreSQL |
| Feature / Foundation / MultiProcessConcurrency | two OS processes: idempotency wait→replay, concurrent duplicate SKU, real PostgreSQL deadlock retried | PostgreSQL |
| Unit / Domain / Procurement, Inventory | PO/GR state machines (transition table), mass-balance property test (400 random cases), balance = Σ ledger property test, reversal rules | no |
| Feature / Inventory | PO flow incl. partial receipts (500 = 200+150+150), over-receipt, direct purchase, all dispositions, supplier return, recovery, reversal (incl. double / after stock used), operations, idempotent retries, stale version 409, master-data validation, UOM policy, audit events, projection verify/rebuild, random-operation property test over HTTP, product-FK registry | PostgreSQL |
| Feature / Inventory / LedgerDatabaseConstraints | raw SQL: ledger UPDATE/DELETE refused, type↔direction, reversal mirror/once, balance ≥ 0, posted receipt immutable, mass balance at POSTED, PO line rules, line-total CHECK | PostgreSQL |
| Feature / Inventory / InventoryMultiProcess | OS processes: double posting, same-key wait→replay, concurrent decrement, 6 processes racing for 4 units, concurrent reversal, lost-update on projection, reversal racing a loss | PostgreSQL |
| Unit / Domain / Sales | sale state-machine table, exact totals, walk-in/cash/credit rules, credit-limit boundary, overpayment, random payment/reversal sequences (outstanding deterministic), return pricing property test (Σ returned = line total), rejected commands leave the aggregate untouched | no |
| Feature / Sales | direct / walk-in / B2B sales, partial payments, insufficient stock without partial effects, credit limit, due & overdue, payment and sale reversal, returns with dispositions and refunds, retries, stale version, master-data guard, audit, owner queries, EXPLAIN on receivable index | PostgreSQL |
| Feature / Sales / SalesDatabaseConstraints | raw SQL: payments append-only, unique payment reversal, cached sums vs history (deferred trigger), outstanding ≥ 0, confirmed sale immutable, walk-in cannot owe, return counters | PostgreSQL |
| Feature / Sales / SalesMultiProcess | stock race, 6 simultaneous sales, payment race, credit-limit race, double confirmation, double reversal | PostgreSQL |
| Feature / Inventory / A3Hardening | stale version on GR post/reverse/cancel, inactive supplier role / node / product, recovery from quarantine, paged balances | PostgreSQL |
| Feature / DataEvolution | data contract = real schema (every table declared, UUID PKs, declared immutability enforced); stable identity guards; fingerprint determinism, change and history-rewrite detection; trace; schema catalog freshness; destructive commands prohibited in production; idempotency key in audit context | PostgreSQL |
| Feature / Lots (A5-B) | lot created once per posted GR line, retries, regrade lineage, FIFO single/multiple lots, sale/return/operation allocation, reversals, legacy lot; raw-SQL lot guards (immutable, no delete, product/source mismatch, origin quantity); lot read API + no-N+1 query counts; trace; fingerprint reconciliation + REAL rollback/re-migrate over pre-A5-B history | PostgreSQL |
| Feature / Lots / LotMultiProcess | OS processes: two 40 kg sales on lot A (50), exhausted lot, 7 simultaneous sales over 3 lots, concurrent reversal of the same payment | PostgreSQL |
| Feature / Sales / CreditLimitOverride | OWNER override with reason, audit fields, retry, not-required / cash / walk-in refusals, permission denial via config, concurrent overrides (OS process) | PostgreSQL |
| Feature / Operations | dashboard per business date, activity + receivable change, search, /config defaults, Asia/Jakarta midnight boundary (with a wrong DB session timezone), bootstrap idempotency / no default password / STDIN, readiness without leaks, request-id ↔ log ↔ audit, doctor | PostgreSQL |
| Feature / Contract / MobileApiContract | response shapes of the core mobile flow, error envelope + statuses, idempotency headers, every error code documented | PostgreSQL |
| Feature / Printing (A5-C) | receipts (walk-in, B2B partial, CASH/BANK_TRANSFER/QRIS, payment & sale reversal, cancel, exact money/quantity, 58/80 mm), lot labels (direct farm, agent, LEGACY, REGRADE), PO/GR print, QR determinism & no sensitive data, scan resolver (QR, lot number, SKU, unknown, ambiguous, invalid, future version, unauthenticated, read-only), print settings | PostgreSQL |
| Feature / Lots / CollectionDate (A5-C) | collection_date null/preserved/never inferred, validation, supplier reference, regrade inheritance, DB guards, migration never backfills | PostgreSQL |
| Unit / Application / PrintingContract | QR codec, exact formatting, print-settings validation | no |
| Feature / Foundation | Schema & constraints, transaction commit/rollback, append-only audit, idempotency (incl. concurrent request blocking and HTTP replay), document sequences, authentication API, operator console command | PostgreSQL |

## Why `DatabaseTruncation` instead of `RefreshDatabase`

`RefreshDatabase` runs each test inside a transaction that is rolled back.
That would make it impossible to prove real COMMIT/ROLLBACK behaviour, which
is the point of the foundation tests. Feature tests therefore extend
`Tests\DatabaseTestCase`, which truncates tables after each test and keeps the
`uoms` reference data.

## Time

Tests use `Tests\Support\FrozenClock` (default 2026-10-03 01:00 UTC =
08:00 WIB) so business-date logic is deterministic.

## Multi-process tests

`tests/Support/worker.php` is started as a separate PHP process
(`Tests\Support\WorkerProcess`) with the same environment, so it talks to the
same test database through its own connection. It reports `ACQUIRED` while
holding a lock/key in an open transaction and `DONE` after commit; the test
does the competing operation in between. These tests take ~1–3 s each.

A3 scenarios: `hold-post-receipt` (optionally through the idempotent
executor with a key), `hold-reverse-receipt`, `hold-loss`, and `loss-on-go`
(waits for "go" on STDIN, reports `RESULT ok|<error code>`), used to start
several competing processes at the same moment.

A4 scenarios: `hold-confirm-sale`, `hold-payment`, `hold-reverse-sale`,
`hold-rebuild`, and `sale-on-go` (simultaneous walk-in sales).

A5-B scenarios: `hold-reverse-payment` (payment reversal holding the sale
lock) and `hold-confirm-override` (credit override holding the customer
credit lock); lot races reuse `hold-confirm-sale` and `sale-on-go`.

## Performance sanity (dev only)

`php artisan dgway:dev:perf --seed` (refuses APP_ENV=production) seeds ~1000
products, ~1000 partners, 400 receipts (1200 lots) and 3000 sales through the
real use cases into the CURRENT database (use a scratch DB), then prints
median latency, SQL statement count per read model and EXPLAIN ANALYZE of the
hot statements. Result: docs/evidence/a5b-performance.md.
