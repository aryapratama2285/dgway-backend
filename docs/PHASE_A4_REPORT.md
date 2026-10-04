# Phase A4 — Sales, Payment & Accounts Receivable · Verification Report

Date: 2026-10-03 · PHP 8.3.6 · PostgreSQL 16 · Laravel 13.34.0 · PHPUnit 12.5.37
Baseline: `dgway-backend-phase-a3.tar.gz` (A1–A3 architecture kept).

Status vocabulary: IMPLEMENTED · TESTED · PARTIALLY TESTED · NOT TESTED · NOT IMPLEMENTED

## Results

- Full suite: **429 tests, 18 120 assertions, 0 failures** (Unit 210 · Feature 201 · Architecture 18)
  — `docs/evidence/a4-test-run.txt` (A3 had 339).
- Migrations: fresh → rollback of the A4 migration only (A1–A3 intact, A4
  functions dropped) → re-migrate → full reset → migrate — `docs/evidence/a4-migrations.txt`.
- Live HTTP smoke test, full owner flow + Scenarios A–F —
  `docs/evidence/a4-smoke-test.sh`, `a4-smoke-test-output.txt`.
- Negative checks (credit lock, sale lock) — `docs/evidence/a4-negative-checks.txt`.
- A3 hardening, failing tests before the fixes — `docs/evidence/a4-a3-hardening-before-fix.txt`.

## Acceptance criteria

| # | Criterion | Status |
| --- | --- | --- |
| 1 | Direct sale without customer order | TESTED |
| 2 | Walk-in cash sale | TESTED |
| 3 | B2B credit sale | TESTED |
| 4 | Partial payment | TESTED |
| 5 | Deterministic outstanding | TESTED (domain property test + DB generated column + deferred history check) |
| 6 | Overpayment rejected | TESTED (incl. multi-process) |
| 7 | Credit limit concurrency-safe | TESTED (multi-process + negative check) |
| 8 | Sale produces SALE movement | TESTED |
| 9 | Negative stock impossible | TESTED (multi-process, 2 and 6 processes) |
| 10 | Immutable payment history / reversal | TESTED (raw SQL + live psql) |
| 11 | Traceable sale reversal | TESTED (incl. double reversal, multi-process) |
| 12 | Return ≤ sold quantity | TESTED (domain, API, DB CHECK) |
| 13 | Retries do not duplicate | TESTED (sale, payment, return, payment reversal; live) |
| 14 | Multi-process tests | TESTED (6 sales scenarios + 2 rebuild scenarios) |
| 15 | Audit events | TESTED |
| 16 | PostgreSQL constraint tests | TESTED |
| 17 | Migration / rollback / re-migrate | TESTED |
| 18 | Architecture tests | TESTED |
| 19 | A3 hardening | TESTED (A–E) |
| 20 | Live end-to-end HTTP smoke test | TESTED |

## Defects found

1. **A3 — `POST /goods-receipts/{id}/cancel` ignored `expected_version`**
   (stale cancel succeeded). Failing test first, fixed.
2. **A3 — `GET /inventory/balances` was not paged** (returned every row).
   Failing test first, fixed (page / per_page, max 100).
3. **A4 (during implementation) — a COMPLETED sale could be confirmed again**
   in the domain, because COMPLETED → CONFIRMED is a legal transition (for
   payment reversal). Found by a feature test; `confirm()` now requires
   DRAFT; regression unit test added. The DB trigger (confirmed sale
   immutable) would have rejected the write anyway.
4. **A4 (during implementation) — a rejected return / confirm could leave the
   in-memory aggregate half-changed** (rolled back in the database, but not
   clean domain behaviour). Commands now decide first and mutate after;
   unit test proves the aggregate is untouched after a rejection.

## Decision change to flag

D6 (credit limit = warning only) is superseded by A4 §19 (reject). Implemented
as a hard rejection (`CREDIT_LIMIT_EXCEEDED`).

## Not tested / limitations

- Credit limit has no database constraint (it spans rows); the customer row
  lock is the guarantee (negative check proves it matters).
- A payment reversal may raise a customer's exposure above the limit (not
  checked; correcting a wrong payment must stay possible).
- Concurrent reversal of the same payment is not multi-process tested
  (sale lock + UNIQUE payment_id; the UNIQUE path is tested with raw SQL).
- Performance with large data not measured (candidates listed in
  docs/SALES_AND_RECEIVABLES.md §11).

## Not implemented on purpose

Customer orders / fulfillment / delivery, printer, Flutter, payment gateway,
deposits / prepayments / customer credit balance, price master, reversal of a
customer return, COGS/HPP, accounting/GL, tax, promotions.
