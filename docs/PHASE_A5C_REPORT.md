# Phase A5-C report — Printing / scanning contract, lot upstream facts, backend freeze

Baseline `dgway-backend-phase-a5b.tar.gz`: 495 tests / 19,820 assertions, all green
(re-run before any change: `docs/evidence/a5c-baseline-test-run.txt`).
Result: **525 tests / 20,328 assertions, all green** (`docs/evidence/a5c-test-run.txt`).
Labels: IMPLEMENTED / TESTED / PARTIALLY TESTED / NOT TESTED / NOT IMPLEMENTED.

## 1. Exact changes

| Area | Change | Status |
| --- | --- | --- |
| Lot provenance | optional `collection_date`, `supplier_reference` on `GoodsReceiptLine` and `InventoryLot` (plain value fields, no new aggregate); validation; copied at posting; inherited by REGRADE lots; never on ADJUSTMENT/LEGACY | IMPLEMENTED · TESTED |
| Read models | `/lots`, `/lots/{id}`, GR create/show responses, `dgway:trace` show collection date separately from the DG Way received date | IMPLEMENTED · TESTED |
| Print model | `Application\Printing\PrintDocuments`: RECEIPT, LOT_LABEL, PURCHASE_ORDER, GOODS_RECEIPT in one versioned envelope (`schema_version: 1`) | IMPLEMENTED · TESTED |
| Print settings | `print_settings` (one row), defaults DG WAY / Supply & Distribution / 80 mm from config; `GET`/`PATCH /print/settings` (permission `settings.manage_print`, idempotent, audited) | IMPLEMENTED · TESTED |
| QR / barcode | `IdentifierCodec`: `dgway://<lot\|sale\|product>/<uuid>`, Code 128 of lot number / SKU | IMPLEMENTED · TESTED |
| Scan resolver | `GET /scan/resolve?code=` typed result, stable errors, read-only | IMPLEMENTED · TESTED |
| Fingerprint | per-phase checksum groups (`fingerprint_additions` → `"<table>+a5c"`), new invariant `lots.upstream` | IMPLEMENTED · TESTED |
| Docs | PRINTING_SCANNING_CONTRACT, QR_BARCODE_CONTRACT (new); API_CONTRACT_V1, API_ERROR_CODES, BATCH_LOT_MODEL §13, DATA_EVOLUTION_STRATEGY §14, DATABASE, ARCHITECTURE, README, TESTING, SCHEMA_CATALOG (regenerated) | IMPLEMENTED |

Files — new: `database/migrations/2026_10_05_100100_add_lot_upstream_facts.php`,
`2026_10_05_100200_create_print_settings.php`; `app/Application/Printing/*` (PrintDocuments, PrintFormat,
PrintSettings, PrintSettingsService, PrintSettingsStore, PrintDefaults, PrintQueries, IdentifierCodec,
ScanResolver, ScanRejected, AmbiguousScan); `app/Infrastructure/Printing/{DbPrintQueries, DbPrintSettingsStore}`;
`app/Http/Controllers/Api/V1/Printing/PrintController.php`; tests `Feature/Printing/*` (4 files),
`Feature/Lots/CollectionDateTest`, `Unit/Application/PrintingContractTest`; docs + `docs/evidence/a5c-*`.
Modified: `GoodsReceiptLine`, `GoodsReceipt` (collection ≤ receipt date), `InventoryLot`, `CreateGoodsReceipt`,
`PostGoodsReceipt`, `GoodsReceiptController` (+2 optional fields), `ProcurementResources` (+4 optional line fields),
`DbGoodsReceiptRepository`, `DbLotRepository`, `DbLotQueries`, `TraceCommand`, `DataFingerprintCommand`,
`Permission` (+`MANAGE_PRINT_SETTINGS`), `ApiExceptionRenderer` (404 for `SCAN_CODE_UNKNOWN`), routes, provider,
`config/{dgway,data_contract}.php`.
Modified tests (no test removed or weakened):
- `LotReconciliationTest` (A5-B) — rolls back "every migration from A5-B on" instead of `--step=1`, because A5-C added migrations after A5-B. Same intent and assertions.
- `MobileApiContractTest` — allow-list of non-error strings extended by `LOT_LABEL`, `LOT_NUMBER`, plus one new route-sync test.

## 2. Exact migrations

1. `2026_10_05_100100_add_lot_upstream_facts` (additive): `goods_receipt_lines` + `collection_date date NULL`,
   `supplier_reference varchar(60) NULL`, CHECK `grl_upstream_check`; `inventory_lots` + same columns, CHECK
   `lot_upstream_check` (collection ≤ business_date, ≥ manufactured_date, reference not blank, only RECEIPT/REGRADE
   may carry upstream facts); `dgway_lot_guard` replaced = A5-B text + equality of upstream facts with the receipt
   line (RECEIPT) / parent (REGRADE). **No backfill.** `down()` restores the A5-B guard verbatim (dev only).
2. `2026_10_05_100200_create_print_settings`: table `print_settings` (UUID PK, business_name 60, subtitle 60,
   address 200, phone 30, footer 200, receipt_width_mm ∈ {58,80}, version, updated_by, timestamps), unique index on a
   constant (one row), identity guard (no delete). No printer hardware columns.

## 3. APIs added / changed

Added (bearer): `GET /print/settings`, `PATCH /print/settings` (Idempotency-Key), `GET /sales/{id}/print[?width=58|80]`,
`GET /purchase-orders/{id}/print`, `GET /goods-receipts/{id}/print`, `GET /lots/{id-or-number}/label`,
`GET /scan/resolve?code=`. Changed (additive only): GR lines accept/return `collection_date`, `supplier_reference`
(and now also return `manufactured_date`, `best_before_date`); lot read models return `collection_date`,
`supplier_reference`. New error codes: `COLLECTION_DATE_INVALID`, `SUPPLIER_REFERENCE_TOO_LONG`,
`BUSINESS_NAME_REQUIRED`, `PRINT_FIELD_TOO_LONG`, `RECEIPT_WIDTH_INVALID`, `SCAN_CODE_INVALID`,
`SCAN_CODE_UNKNOWN` (404), `SCAN_CODE_AMBIGUOUS` (409), `SCAN_VERSION_UNSUPPORTED`. No existing response shape
lost a field. Route table ⇔ API_CONTRACT_V1.md catalog enforced by a test (97 routes).

## 4. Lot / collection-date semantics

DG Way InventoryLot ≠ supplier production batch; `collection_date` ≠ received date. Agent purchase → all upstream
facts NULL; direct farm → `collection_date` recorded, `supplier_reference` NULL; supplier with its own reference →
`supplier_reference`. Rule: optional; valid date; ≤ receipt business date; ≥ manufactured date when both given;
no minimum age; never defaulted or inferred. Details: BATCH_LOT_MODEL.md §13.

## 5. Printing boundary

Backend = business truth, semantic print model, stable ids, QR/barcode payloads, resolver, authorization.
Flutter = Bluetooth discovery/pairing/connection, ESC/POS rendering, physical printing, camera/hardware scanning,
retry/reprint UX. D9: Bluetooth ESC/POS thermal, 80 mm primary, 58 mm compatible, no brand; label printing separate.
D11: DG WAY / Supply & Distribution default, configurable. No print history subsystem. PRINTING_SCANNING_CONTRACT.md.

## 6. QR / barcode

QR `dgway://lot/<uuid>` (also `sale`, `product`), lower-case, deterministic, version 1 = canonical form
(`dgway://v1/…` alias accepted; `v2+` → SCAN_VERSION_UNSUPPORTED). Contains only the UUID. Barcode: Code 128 of the
lot number (`LOT-261003-001`) or SKU. QR_BARCODE_CONTRACT.md.

## 7. Scan resolver

QR → entity by id (LOT/PRODUCT/SALE); text → exact upper-cased lot number or SKU; none → 404 `SCAN_CODE_UNKNOWN`
(fixed message, no echo); both → 409 `SCAN_CODE_AMBIGUOUS` with candidate types (deterministic); malformed →
422; unauthenticated → 401; no side effects (no audit/idempotency/movement rows — tested).

## 8. Tests

525 tests / 20,328 assertions (+30). Mapping of the 30 required items:

| # | Test |
| --- | --- |
| 1 retail walk-in · 4 CASH | `ReceiptPrintTest::test_walk_in_retail_receipt` |
| 2 B2B · 3 partial · 5 BANK_TRANSFER · 6 QRIS | `ReceiptPrintTest::test_b2b_partial_payment_with_every_method_and_outstanding` |
| 7 reversal/cancel | `ReceiptPrintTest::test_payment_reversal_and_sale_reversal_and_cancellation_are_represented` |
| 8 normal label · 27 collection ≠ received · 28 direct farm | `LotLabelAndDocumentPrintTest::test_label_of_a_direct_farm_lot…`, `CollectionDateTest::test_direct_farm_lot…` |
| 9 LEGACY label | `LotLabelAndDocumentPrintTest::test_label_of_a_legacy_lot…` |
| 10 REGRADE label | `LotLabelAndDocumentPrintTest::test_label_of_a_regrade_lot…`, `CollectionDateTest::test_supplier_reference…regrade` |
| 11 deterministic QR · 12 no sensitive data | `ScanResolveTest::test_qr_payload_is_deterministic…`, `PrintingContractTest::test_qr_codec_round_trip…` |
| 13 QR resolution · 14 lot number · 15 SKU | `ScanResolveTest::test_qr_lot_number_and_sku_resolve…` |
| 16 unknown | `ScanResolveTest::test_unknown_invalid_and_future_codes…` |
| 17 ambiguous | `ScanResolveTest::test_ambiguous_text_is_refused_deterministically…` |
| 18 unauthenticated | `ScanResolveTest::test_resolver_requires_authentication_and_has_no_side_effects` |
| 19 config defaults | `PrintSettingsTest::test_defaults_are_dg_way…` |
| 20 58/80 mm | `PrintSettingsTest` (update/validation), `ReceiptPrintTest::test_receipt_width…` |
| 21 exact money · 22 exact quantity/UOM | `ReceiptPrintTest::test_exact_money_and_quantity_representation`, `PrintingContractTest::test_exact_money_and_quantity_formatting` |
| 23 NULL allowed · 25 not auto-populated · 29 agent | `CollectionDateTest::test_agent_sourced_lot…`, `LotLabelAndDocumentPrintTest::test_label_of_an_agent_lot…` |
| 24 preserved | `CollectionDateTest::test_direct_farm_lot…`, `…test_supplier_reference…` |
| 26 migration does not fabricate | `CollectionDateTest::test_migration_never_fabricates_collection_dates_for_existing_lots` (real rollback/re-migrate) |
| 30 docs synchronized | `MobileApiContractTest::test_every_api_route_is_documented_in_the_contract`, `…test_every_error_code_is_documented`, schema catalog freshness test |
| extra | validation (`CollectionDateTest::test_impossible_collection_dates…`), DB guards (`…test_the_database_refuses_invented_upstream_facts`), permission (`PrintSettingsTest::test_only_permitted_operators…`), PO/GR print |

## 9. Smoke evidence (live HTTP, fresh DB, `docs/evidence/a5c-smoke-test-output.txt`)

Defaults DG WAY / Supply & Distribution / 80 mm. Direct farm: PO-261003-001 150 kg; GR-261003-001 with
`collection_date 2026-10-02` received `2026-10-03` (140 ACCEPT / 10 LOSS) → LOT-261003-001 with
`collection_date=2026-10-02`, `business_date=2026-10-03`, `supplier_reference=NULL` (no fictitious batch).
Retail 30 kg → Rp810.000 COMPLETED; B2B 50 kg Rp1.275.000 paid Rp775.000 (Transfer Bank 500.000 + QRIS 275.000),
outstanding Rp500.000, due 2026-10-10 (printed at 58 mm). Stock 140 → 60; allocation 30 + 50 from LOT-261003-001.
Label lines: Diterima DG Way 2026-10-03 / Tanggal Pengumpulan 2026-10-02 / Pemasok / Penerimaan / Jumlah Awal 150 KG.
QR `dgway://lot/01a101be-…` → resolves to the same lot (QR and barcode `LOT-261003-001`), SKU resolves to the product,
unknown → 404, no token → 401, QR security check: canonical, no forbidden fragments. Agent: GR-261003-002 without
collection date → LOT-261003-002 `collection_date=NULL`; label has no collection/production line. Receivable 500.000,
dashboard/activity/integrity/trace correct; print/scan audit rows = 0. JSON examples: `docs/evidence/a5c-print-examples/`.

## 10. Regression evidence

- Full suite incl. PostgreSQL feature tests and multi-process concurrency tests: 525 / 20,328 green.
- Migration from A5-B databases (`a5c-migration-roundtrip.txt`), dev DB (A4 history) and A5-B smoke DB:
  up → `--allow-additions` identical (additions: `lots.upstream`, `goods_receipt_lines+a5c`, `inventory_lots+a5c`,
  `print_settings`); existing lots/lines with collection date = 0; verify OK; rollback → strict identical;
  re-migrate → strict identical; verify OK.
- Fresh migrate + bootstrap (`a5c-fresh-migrate.txt`, `a5c-bootstrap.txt`).
- Backup/restore drill (`a5c-backup-restore-drill.txt`): fingerprint identical, verify OK, collection dates
  survived, append-only guard active, doctor OK.
- Schema catalog regenerated; freshness test green; readiness covered by the existing suite.

## 11. Limitations

- Printed wording is Indonesian only (labels in `display_lines`, method labels, notices); no localisation switch.
- Money `formatted` uses id-ID conventions with `Rp` (IDR only, as the whole system).
- Products have no manufacturer barcode (EAN/GTIN); product barcode = SKU.
- A SKU equal to a lot number makes text scans ambiguous (QR still works); SKU creation does not forbid it.
- No logo/image in print settings; no separate payment-receipt or return-receipt document (the sale receipt shows payments, reversals and returns).
- Live smoke ran on `php artisan serve`, not Nginx/PHP-FPM.

## 12. Backend freeze review

Endpoints Flutter needs for the daily cycle — all present and documented (97 routes): auth, config, master data,
PO/GR (+ upstream facts), inventory operations, sales/payments/returns/reversals, receivables, dashboard/activity/
search, lots, print documents, print settings, scan resolve, health/readiness.

| Finding | Class |
| --- | --- |
| — (no issue prevents Flutter from operating purchase → receive → lot → sell → pay → receivable → print → scan) | **A. BLOCKER BEFORE FLUTTER: none** |
| No operator password change/reset (API or console); workaround: `dgway:operator:create` a new account | B. POST-FLUTTER HARDENING |
| Error messages mix Indonesian and English (codes are stable; UI should map codes) | B |
| Separate payment/return receipt documents, logo in print header, localisation of printed labels | B |
| Search uses LIKE without trigram index (fine at measured volume) | B |
| TRUNCATE not trigger-guarded (DB privilege in deployment); production on Nginx/PHP-FPM not yet smoke-tested | B |
| Duplicate SKU vs lot-number codes not prevented at SKU creation | B |
| FEFO / expiry policy; product EAN/GTIN; HPP/costing; operator roles beyond OWNER | B (business features when needed) |
| Outbox/event stream, multi-tenant/organisation dimension, marketplace/supplier portal, multi-node transfers, microservices/Kafka | C. PROJECT B / FUTURE PLATFORM |

## 13. Conclusion

No BLOCKER remains.

**PROJECT A BACKEND READY FOR FLUTTER**
**PROJECT A BACKEND FREEZE APPROVED**

(Freeze = no further backend features before Flutter; class-B items are scheduled after Flutter. Final
approval rests with the external audit.)
