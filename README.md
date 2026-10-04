# DG Way — Owner Operating App (Project A) · Backend

Internal operating system for DG Way's physical commerce & distribution business
(eggs as the entry product). This repository is the **Laravel/PHP + PostgreSQL
REST API**. The Flutter Android client is a separate project (Phase A5).

> Status: **Phase A5-C — printing/scanning contract, lot upstream facts, BACKEND FREEZE** (see `docs/PHASE_A5C_REPORT.md`).
> Previous: **Phase A5-B — Operational readiness + inventory batch/lot foundation**
> (on top of A1–A4 and the A5 data-evolution addendum). Every received quantity
> becomes a traceable lot (supplier → receipt → FIFO sale allocation → customer,
> returns back into the original lot); owner dashboard, daily activity, search,
> operational defaults, OWNER-only credit override, bootstrap, readiness probe,
> request correlation, backup/restore and deployment guides. Printing/scanning
> (A5-C), Flutter app, orders/delivery, accounting and HPP are NOT implemented.
> See `docs/PHASE_A5B_REPORT.md`.

## Principles

- Business domain → use case → domain model → database → API → UI.
- Object-oriented, layered: `Domain` / `Application` / `Infrastructure` / `Http`.
- Money and quantities are decimals end-to-end (`NUMERIC`, never float).
- Every final transaction is atomic, audited, and idempotent (safe to retry).
- Online-first: the server is the source of truth.

## Quick start

See [SETUP.md](SETUP.md). Short version:

```bash
cp .env.example .env && php artisan key:generate
php artisan migrate
printf '%s' "$OWNER_PASSWORD" | php artisan dgway:bootstrap --owner-email=owner@example.com --owner-name="Owner" --password-stdin
#   (idempotent: units, node DG-WAY-PRIMARY, WALK-IN customer, owner; never a default password)
php artisan serve
# maintenance
php artisan dgway:inventory:verify            # projection vs ledger
php artisan dgway:inventory:rebuild-balances  # recompute projection from the ledger
php artisan dgway:data:fingerprint --output=before.json   # reconciliation before/after a migration (--compare)
php artisan dgway:trace SAL-261003-001        # follow a transaction: documents, ledger, money, actors, audit
php artisan dgway:trace LOT-261003-001        # follow a lot: supplier, receipt, dispositions, lineage, sales, customers, audit
php artisan dgway:schema:catalog              # regenerate docs/SCHEMA_CATALOG.md
php artisan dgway:doctor --production         # production configuration sanity (exit 1 = unsafe)
curl http://localhost:8000/api/v1/health/ready
```

## Documentation

| File | Content |
| --- | --- |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Layers, dependency rule, transaction / idempotency / audit design |
| [SETUP.md](SETUP.md) | Local setup with PostgreSQL |
| [TESTING.md](TESTING.md) | Test suites and how to run them |
| [DOMAIN_MODEL.md](DOMAIN_MODEL.md) | Aggregates, value objects, invariants |
| [API.md](API.md) | Endpoint overview per phase |
| docs/API_CONTRACT_V1.md | **The mobile API contract**: transport, formats, pagination, errors, idempotency, expected_version, every endpoint |
| docs/API_ERROR_CODES.md | Every error code with HTTP status (kept complete by a test) |
| docs/MOBILE_IDEMPOTENCY.md | Client retry algorithm for the Flutter app |
| docs/PRINTING_SCANNING_CONTRACT.md | Device-neutral print documents (receipt, lot label, PO, GR), print settings, backend/Flutter boundary |
| docs/QR_BARCODE_CONTRACT.md | QR payload `dgway://lot/<uuid>`, Code 128 barcode, scan resolver rules, versioning |
| docs/BATCH_LOT_MODEL.md | Lot identity, creation, lineage, dispositions, FIFO, allocation, returns, concurrency |
| docs/BACKUP_RESTORE.md | Backup, restore into a new environment, verification (drill executed) |
| docs/DEPLOYMENT.md | Production environment, first install, release procedure, observability |
| [DATABASE.md](DATABASE.md) | Tables and constraints implemented so far |
| docs/IDEMPOTENCY.md | Idempotency contract and key retention |
| docs/INVENTORY_LEDGER.md | Ledger design, receipt posting plan, projection, concurrency, reversal |
| docs/DATA_EVOLUTION_STRATEGY.md | Generation-1 data contract: stable identity, ownership, ledger/financial continuity, reconciliation, Project B readiness |
| docs/SCHEMA_CATALOG.md | Generated schema catalog (ownership, immutability, constraints, cross-context FKs) |
| docs/SALES_AND_RECEIVABLES.md | Sale/payment/return model, credit limit, receivable calculation, locking |
| docs/PHASE_A1_REPORT.md … docs/PHASE_A5C_REPORT.md | Phase verification reports (A5-C includes the backend freeze review) |
| docs/evidence/ | Raw outputs: test runs, migrations, live smoke tests |

Remaining documents from the master brief (ANDROID_BUILD,
PRINTER_SETUP, MIGRATION_TO_PROJECT_B) are written in the phases that
implement those parts.
