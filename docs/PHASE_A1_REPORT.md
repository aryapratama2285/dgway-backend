# Phase A1 — Foundation · Verification Report

Date: 2026-10-03 · Environment: Ubuntu 24.04, PHP 8.3.6, PostgreSQL 16.15,
Laravel 13.34.0, Sanctum 4.3.3, brick/math 1.0.0, PHPUnit 12.5.37.

Status vocabulary: IMPLEMENTED · TESTED · PARTIALLY TESTED · NOT TESTED · NOT IMPLEMENTED

## Results

- `php artisan migrate` — 9 migrations ran on PostgreSQL 16.15; rollback of all
  9 and re-migrate also ran (`docs/evidence/a1-migrate-status.txt`).
- `phpunit` — **102 tests, 270 assertions, 0 failures, 0 errors**
  (Unit 56 · Feature 42 · Architecture 4) — `docs/evidence/a1-test-run.txt`.
- Live HTTP smoke test via `php artisan serve` + curl: health, 401 without token,
  wrong password, login, me, logout, token rejected after logout, audit rows
  written, `DELETE FROM audit_logs` rejected by trigger.

## Component status

| Component | Status |
| --- | --- |
| Laravel 13 project, PHP 8.3, API-only | TESTED |
| PostgreSQL connection (UTC session time zone) | TESTED |
| Sanctum bearer tokens (hash stored, 30-day expiry, revoke on logout) | TESTED |
| Login / logout / me endpoints, rate limit, audit of success & failure | TESTED |
| Active-operator authorization middleware | TESTED |
| Operator creation console command (no default passwords) | TESTED |
| Domain / Application / Infrastructure / Http layering + dependency rule | TESTED (architecture test, incl. negative check) |
| Decimal, Money, Quantity, Uom, BusinessDate, DocumentNumber, Actor | TESTED (unit) |
| TransactionManager (commit, rollback, nested join, constraint rollback) | TESTED |
| Deadlock / serialization retry (3 attempts) | IMPLEMENTED, NOT TESTED |
| AuditRecorder + append-only trigger | TESTED |
| Idempotency (replay, payload conflict, per-operator scope, rollback frees key, concurrent request blocks, HTTP 428/400/409 + replay header) | TESTED |
| Document number generator (per prefix/day, no gap after rollback) | TESTED |
| Error envelope for API errors | TESTED (401/403/404/409/422/428/429); 500 path NOT TESTED |
| UOM reference table (KG 3, PCS 0, IKAT 0) | TESTED |
| HTTPS | NOT TESTED (deployment concern, after A4) |
| Business modules (A2–A7), Flutter app, printing, scanning | NOT IMPLEMENTED |

## Known limitations / notes

- Packagist was blocked in the build sandbox; dependencies were resolved from
  GitHub sources. `composer.json` is the normal Packagist form and
  `composer.lock` pins the verified versions; `composer install` on a normal
  machine was NOT TESTED here.
- `league/flysystem` installed from source produced harmless "ambiguous class"
  autoload warnings; they do not occur with normal dist installs.
- Personal access token ids are bigint (Sanctum token format), the only non-UUID key.
