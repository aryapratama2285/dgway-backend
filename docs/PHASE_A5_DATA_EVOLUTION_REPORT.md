# Phase A5 (addendum) — Data Evolution & Project B Compatibility · Report

Date: 2026-10-03 · Baseline: `dgway-backend-phase-a4.tar.gz` (checksum-identical to the delivered A4).
Scope: the A5 addendum only. The printing/scanning scope of A5 is NOT started
(needs the A5 brief and decisions D9 printer, D11 receipt header).

## Results
- Full suite: **445 tests, 18 771 assertions, 0 failures** — `docs/evidence/a5-data-evolution-test-run.txt`.
- Reconciliation evidence on the dev DB (schema rollback + re-migrate → fingerprint identical; rebuild → identical; trace) — `docs/evidence/a5-data-evolution-reconciliation.txt`.
- Migration of the new guard: rollback removes the 8 triggers, re-migrate restores them — `docs/evidence/a5-data-evolution-migrations.txt`.
- Failing tests before the fixes — `docs/evidence/a5-data-evolution-before-fix.txt`.

## Delivered
| Item | Status |
| --- | --- |
| docs/DATA_EVOLUTION_STRATEGY.md (12 sections incl. readiness assessment) | IMPLEMENTED |
| config/data_contract.php + DataContractTest (every table declared; guarantees exist in DB) | TESTED |
| DB identity guard on master tables + users (no delete, immutable UUID/business key) | TESTED |
| Production prohibits destructive migration commands | TESTED |
| `dgway:data:fingerprint` (invariants + per-table checksums, `--compare`) | TESTED |
| `dgway:trace` (number / document / payment / movement / correlation id) | TESTED |
| `dgway:schema:catalog` + generated docs/SCHEMA_CATALOG.md (`--check` in the suite) | TESTED |
| Audit entries carry the request's Idempotency-Key and command | TESTED |

## Defects / gaps found (failing test first, then fixed)
1. Master records could be hard-deleted (when unreferenced) and their UUID / business key changed by raw SQL; "no hard delete" was enforced only by the API. Fixed with `dgway_guard_identity`. Three A2 constraint tests now hit this guard before the RESTRICT FK; they assert both (FK still ON DELETE RESTRICT).
2. Audit entries did not contain the Idempotency-Key, although the A1 retention docblock said so. Fixed with `AuditScope`.

## Not done / limitations
- No outbox / event stream, no tenant dimension, PO status not DB-enforced — listed as technical debt in the strategy §12 (not changed: out of A5 scope).
- Reconciliation was exercised on schema evolution within Project A, not on a real migration to another system (none exists yet).
