# Phase A2 — Master Data · Verification Report

Date: 2026-10-03 · PHP 8.3.6 · PostgreSQL 16.15 · Laravel 13.34.0 · PHPUnit 12.5.37

Status vocabulary: IMPLEMENTED · TESTED · PARTIALLY TESTED · NOT TESTED · NOT IMPLEMENTED

## Results

- Full suite: **232 tests, 818 assertions, 0 failures** (Unit 116 · Feature 108 · Architecture 8)
  — `docs/evidence/a2-test-run.txt`
- Migrations on PostgreSQL: fresh → rollback A2 only (A1 tables intact) →
  re-migrate → full reset → migrate from zero — `docs/evidence/a2-migrations.txt`
- Live HTTP smoke test (`php artisan serve` + curl, 20 calls) —
  `docs/evidence/a2-smoke-test.sh`, `a2-smoke-test-output.txt`

## Component status

| Component | Status |
| --- | --- |
| Product categories (tree, cycle/depth guard, in-use deactivation guard) | TESTED |
| Products (SKU unique/immutable, base UOM, rename/recategorize/describe, UOM lock hook) | TESTED |
| UOM lock "after transactions" | IMPLEMENTED; hook returns "not used" until A3 registers transaction tables — the locked path is TESTED at domain level only |
| Business partner + supplier/customer roles, payment terms, walk-in cash rule | TESTED |
| Phone normalisation + search by phone in any format | TESTED |
| Address value object (embedded columns, DB checks) | TESTED |
| Locations, nodes, capabilities, location/node activation rules | TESTED |
| Default node bootstrap `dgway:network:bootstrap` | TESTED |
| Idempotency on all 25 mutating master-data endpoints | TESTED (create/update/activate paths of each resource; every route is in the `idempotency` group) |
| Duplicate codes under concurrency (two OS processes) | TESTED |
| Optimistic concurrency (`expected_version`) | TESTED |
| Audit events (created/updated/activated/deactivated, role events) | TESTED |
| Deactivate without breaking history (FK RESTRICT, records readable) | TESTED |
| DB constraints bypassing the application | TESTED (28 constraint assertions) |
| A1 hardening: idempotency wait → replay across processes | TESTED |
| A1 hardening: deadlock retry (real PostgreSQL deadlock, 2 processes) | TESTED |
| A1 hardening: serialization-failure (40001) retry | TESTED — was NOT working in A1 (see changes) |
| A1 hardening: 500 responses leak nothing | TESTED |
| A1 hardening: idempotency key retention (prune command + daily schedule) | TESTED (command); cron in production NOT TESTED |
| Flutter UI, purchasing, inventory, sales, payment | NOT IMPLEMENTED (by design) |

## Defects found and fixed during A2

1. **A1 retry gap** — Laravel's default detector did not treat PostgreSQL
   SQLSTATE 40001 (serialization failure) wrapped in `QueryException` as
   retryable. Fixed with `PostgresConcurrencyErrorDetector`; a negative check
   confirmed the test fails without it.
2. **NULL-unsafe CHECK** — `customer_type IN (…)` evaluates to NULL for a NULL
   type and passed the CHECK; a CUSTOMER role without type was accepted by
   the database. Caught by the constraint test, fixed with `IS NOT NULL`.
3. **Replay byte order** — `jsonb` re-ordered keys of stored responses.
   Changed to `json`; replay now byte-identical (checked by md5 live).
4. Test bugs (expectation errors, not product defects): description no-op
   test, self-referencing FK creation order in a migration.

## Not tested / limitations

- Production scheduler cron and HTTPS (deployment phase).
- Subtree depth when moving a category with descendants is not re-checked
  (only the moved category's own depth).
- `BootstrapPrimaryNode`: if the site location exists but the node does not
  (manual deletion scenario), bootstrap fails with DUPLICATE_CODE.
- `composer install` outside the sandbox (Packagist blocked here).
