# Printing & scanning contract (Phase A5-C)

## 1. Boundary

| BACKEND (Laravel) — this repository | FLUTTER / device — not here |
| --- | --- |
| business truth (amounts, statuses, lots) | Bluetooth discovery, pairing, connection |
| **semantic print model** (versioned JSON, no bytes) | ESC/POS (or any printer language) rendering |
| business identity (`/print/settings`) | paper-width layout (80 / 58 mm), fonts, logo |
| stable ids, QR payload, barcode value | QR / barcode **image** rendering |
| scan **resolution** (`/scan/resolve`) | camera scanning, hardware scanner input |
| authentication / authorization | print retry, reprint UX, printer selection |

The backend never sends ESC/POS bytes, never stores a printer (no MAC address,
pairing, model or SDK setting) and implements no Bluetooth. Reprint = request
the same print document again (read-only, deterministic). There is **no**
print job / queue / history / telemetry subsystem.

**Decision D9 (printer).** Primary receipt target: Bluetooth ESC/POS thermal
printer, **80 mm**; compatibility **58 mm**. No brand is chosen. Lot labels
are a separate rendering concern: the receipt printer is not assumed to be
the label printer (`layout.medium = LABEL` has no paper width).

**Decision D11 (identity).** Default header **DG WAY / Supply & Distribution**,
configurable (address, phone/WhatsApp, footer optional). No personal data is
hard-coded (defaults in `config/dgway.php`, overridable by env and by
`PATCH /print/settings`).

## 2. Envelope (all print documents)

```json
{ "data": {
  "schema_version": 1,
  "document_type": "RECEIPT | LOT_LABEL | PURCHASE_ORDER | GOODS_RECEIPT",
  "generated_at": "2026-10-03T12:30:05+00:00",
  "business": { "business_name": "DG WAY", "business_subtitle": "Supply & Distribution", "address": null, "phone": null, "footer_text": null },
  "layout": { "medium": "RECEIPT", "paper_width_mm": 80, "supported_widths_mm": [58, 80] },
  "document": { … } } }
```

- `schema_version` increases only on a **breaking** change; new fields may be
  added under version 1 — the app must ignore unknown fields.
- Values the printer shows are already decided by the backend:
  money `{ "amount": "1275000.0000", "formatted": "Rp1.275.000" }`, quantity
  `{ "value": "50.0000", "formatted": "50", "uom": "KG" }`. Print `formatted`;
  never recompute totals, outstanding or quantities. Formatting is exact
  (string arithmetic): `33344.5` → `Rp33.344,5`, `1.235` kg → `1,235`.
- `business.footer_text` is the receipt footer.
- `null` = not applicable / not known → print nothing for it.

## 3. Print settings

`GET /print/settings` → `{ business_name, business_subtitle, address, phone, footer_text, receipt_width_mm, supported_receipt_widths_mm: [58, 80], version, is_default }`.
`PATCH /print/settings` (Idempotency-Key, permission `settings.manage_print`, audited `print_settings.updated`):
partial update; absent key = unchanged, `null` = clear; `receipt_width_mm` ∈ {58, 80}; `expected_version` optional.
One row at most (`print_settings`); until saved, the defaults are served (`is_default: true`).
Per print, `?width=58|80` on the receipt overrides the stored preference (UI choice per printer).

## 4. RECEIPT — `GET /sales/{id}/print[?width=58|80]`

`document` (real output, B2B partial payment, from `docs/evidence/a5c-print-examples/receipt-b2b-58mm.json`):

```json
{ "sale_id": "01a101be-3512-…", "number": "SAL-261003-002", "status": "CONFIRMED", "is_final": true, "notice": null,
  "business_date": "2026-10-03", "transaction_at": "2026-10-03T12:30:05+00:00", "reversed_at": null, "currency": "IDR",
  "is_walk_in": false, "customer": { "code": "CUS-CATERING-ABC", "name": "Catering ABC" },
  "operator": { "id": "…", "name": "Owner" }, "node": { "code": "DG-WAY-PRIMARY", "name": "…" },
  "lines": [ { "line_no": 1, "sku": "TELUR-STD", "name": "Telur Ayam Standar",
               "quantity": { "value": "50.0000", "formatted": "50", "uom": "KG" },
               "unit_price": { "amount": "25500.0000", "formatted": "Rp25.500" }, "discount": null,
               "line_total": { "amount": "1275000.0000", "formatted": "Rp1.275.000" }, "returned_quantity": null } ],
  "totals": { "subtotal": {…"Rp1.275.000"}, "discount_total": {…"Rp0"}, "grand_total": {…"Rp1.275.000"},
              "total_paid": {…"Rp775.000"}, "credited": {…"Rp0"}, "outstanding": {…"Rp500.000"} },
  "payments": [ { "number": "PAY-261003-002", "method": "BANK_TRANSFER", "method_label": "Transfer Bank",
                  "amount": {…"Rp500.000"}, "paid_at": "…", "reference": "TRF-BCA-A5C", "reversed": false },
                { "number": "PAY-261003-003", "method": "QRIS", "method_label": "QRIS", "amount": {…"Rp275.000"}, … } ],
  "payment_terms": { "term_days": 7, "due_date": "2026-10-10" },
  "returns": [],
  "reference": { "document_number": "SAL-261003-002", "qr_payload": "dgway://sale/01a101be-3512-…" } }
```

Rules:
- **Walk-in**: `is_walk_in: true`, `customer: null`, `payment_terms: null` (no corporate fields). Outstanding is 0 by domain rule.
- **B2B**: customer code + name only (no phone, address, credit limit, available credit); `payment_terms` when the customer has credit terms.
- **Payment methods**: `CASH` → "Tunai", `BANK_TRANSFER` → "Transfer Bank", `QRIS` → "QRIS".
- **Payment reversal**: the reversed payment stays listed with `reversed: true`; `totals` (paid/outstanding) already exclude it (domain figures).
- **Status notice**: `notice` is `null` for a final sale; otherwise `{ code, text }`:
  `DRAFT` "BELUM FINAL — BUKAN BUKTI PEMBAYARAN", `CANCELLED` "DIBATALKAN", `REVERSED` "TRANSAKSI DIBATALKAN (REVERSAL)". Print it prominently.
- Returns: `{ number, business_date, total_value, credit, refund }`; `credited` in totals.

## 5. PURCHASE_ORDER / GOODS_RECEIPT (lightweight, semantic)

- `GET /purchase-orders/{id}/print`: supplier, node, lines (ordered/received quantity, unit cost, line total), `total_amount`, status/notice (DRAFT, CANCELLED).
- `GET /goods-receipts/{id}/print`: supplier, PO number, `business_date` (receipt date), `received_at`, lines with
  `received_quantity`, `dispositions[{ type, quantity, target_sku, reason }]`, `manufactured_date`, `best_before_date`,
  `collection_date`, `supplier_reference`, and the generated **DG Way lot** `{ id, number, qr_payload, barcode }`
  (null while DRAFT). `collection_date` and `business_date` are separate facts.

## 6. LOT_LABEL — `GET /lots/{id-or-number}/label`

`layout = { "medium": "LABEL" }`. `document` (real output, direct-farm lot):

```json
{ "lot_id": "01a101be-3362-71ff-9198-8b13223a7be4", "lot_number": "LOT-261003-001", "origin": "RECEIPT",
  "product": { "sku": "TELUR-STD", "name": "Telur Ayam Standar" }, "node": { "code": "DG-WAY-PRIMARY" },
  "received_by_dgway": { "business_date": "2026-10-03", "received_at": "2026-10-03T12:30:04+00:00" },
  "collection_date": "2026-10-02", "supplier_reference": null,
  "original_quantity": { "value": "150.0000", "formatted": "150", "uom": "KG" }, "uom": "KG",
  "supplier": { "code": "SUP-PETERNAKAN-BANDUNG", "name": "Peternakan Bandung" }, "goods_receipt": { "number": "GR-261003-001" },
  "derived_from": null, "best_before_date": null, "provenance": "RECORDED",
  "display_lines": [
    { "key": "product", "label": "Produk", "value": "TELUR-STD — Telur Ayam Standar" },
    { "key": "received_date", "label": "Diterima DG Way", "value": "2026-10-03" },
    { "key": "collection_date", "label": "Tanggal Pengumpulan", "value": "2026-10-02" },
    { "key": "supplier", "label": "Pemasok", "value": "Peternakan Bandung" },
    { "key": "goods_receipt", "label": "Penerimaan", "value": "GR-261003-001" },
    { "key": "original_quantity", "label": "Jumlah Awal", "value": "150 KG" } ],
  "codes": { "qr": { "payload": "dgway://lot/01a101be-3362-71ff-9198-8b13223a7be4", "contract_version": 1 },
             "barcode": { "symbology": "CODE128", "value": "LOT-261003-001" } } }
```

Wording rules (enforced by the backend, tested):
- The DG Way received date is always **"Diterima DG Way"** — never "Production Date", "Batch Date" or "Tanggal Pengumpulan".
- **"Tanggal Pengumpulan"** appears only when a collection date was recorded; when unknown (agent purchase) the line is **omitted** and `collection_date` is `null` — the received date is never substituted.
- "Ref. Pemasok" only when the supplier gave its own reference. The DG Way lot number is DG Way's identity, not a supplier batch.
- **LEGACY**: no supplier, no receipt, no collection date, no original quantity; `provenance: UNATTRIBUTED_LEGACY` and the line "Asal — Tidak tercatat (stok sebelum pencatatan lot)".
- **REGRADE**: `derived_from.lot_number` + the parent's upstream facts (same goods).
- No remaining/current quantity on a label (it would be wrong tomorrow).

`display_lines` is the ordered ready-to-print text; apps with their own label layout use the structured fields with the same labels.

## 7. Scanning — `GET /scan/resolve?code=<scanned text, URL-encoded>`

Real output (QR of the smoke lot):

```json
{ "type": "LOT", "id": "01a101be-3362-71ff-9198-8b13223a7be4", "matched_by": "QR",
  "display": { "title": "LOT-261003-001", "subtitle": "TELUR-STD · Telur Ayam Standar", "lot_number": "LOT-261003-001",
               "origin": "RECEIPT", "sku": "TELUR-STD", "product_name": "Telur Ayam Standar", "status": "ACTIVE",
               "available": { "value": "60.0000", "uom": "KG" } },
  "links": { "detail": "/api/v1/lots/01a101be-…", "label": "/api/v1/lots/01a101be-…/label" } }
```

`type` ∈ `LOT` | `PRODUCT` | `SALE`; `matched_by` ∈ `QR` | `LOT_NUMBER` | `SKU`. Errors: 404 `SCAN_CODE_UNKNOWN`,
409 `SCAN_CODE_AMBIGUOUS` (`details.candidate_types`), 422 `SCAN_CODE_INVALID` / `SCAN_VERSION_UNSUPPORTED`,
401 without a token. Resolution rules: docs/QR_BARCODE_CONTRACT.md §4. The app sends exactly what it scanned and
branches on `type`; it never infers meaning from the string itself.

## 8. Idempotency, side effects, security

- All print and scan endpoints are **GET, read-only**: no Idempotency-Key, no audit row, no idempotency row, no movement (tested).
  Only `PATCH /print/settings` is a command (Idempotency-Key, audited).
- All require a bearer token of an active operator (existing policy); settings change requires `settings.manage_print` (OWNER).
- Print documents contain no token, password hash, API key, credit limit, available credit, customer phone/address.
  The receipt shows the customer's code and name only.
