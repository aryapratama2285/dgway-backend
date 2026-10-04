# QR & barcode contract (Phase A5-C, contract version 1)

QR and barcode are **identifiers**, not data carriers and not inventory truth.
Everything else (product, quantity, status) is looked up through the
authenticated resolver.

## 1. QR payload

```
dgway://<entity>/<uuid>
```

| Part | Rule |
| --- | --- |
| scheme | `dgway://` (case-insensitive on input; emitted lower-case) |
| entity | `lot` (printed on lot labels), `sale` (printed on receipts), `product` (resolvable, not printed in A5-C) |
| uuid | the entity's stable UUIDv7, **lower-case**, 36 characters |

Example: `dgway://lot/01a101be-3362-71ff-9198-8b13223a7be4`.

Properties:
- **Deterministic**: same entity → same payload, always (no timestamps, nonces or query strings).
- **Durable**: the UUID never changes (Generation-1 identity); a re-printed label is identical.
- **Contains nothing else**: no token, API key, credentials, price, quantity, outstanding, customer
  data, supplier data, collection date or lot number. Leaking a QR reveals only an opaque id that is
  useless without an authenticated session.
- Rendering: the app renders the image (any QR library; error-correction level M recommended;
  the payload is ≤ 54 ASCII characters).

### Versioning

- The canonical form above **is version 1** (no version segment).
- `dgway://v1/<entity>/<uuid>` is accepted as an explicit alias of version 1 but never emitted.
- A future incompatible form will be `dgway://v2/…`. A version-1 backend answers it with
  `422 SCAN_VERSION_UNSUPPORTED` (the app shows "update the app"); version-1 payloads stay valid forever.
- New entity names may be added within version 1.

## 2. Barcode

| Printed on | Value | Symbology (recommended) |
| --- | --- | --- |
| lot label | DG Way lot number, e.g. `LOT-261003-001` | Code 128 |
| product (if the owner prints shelf/bag labels) | product SKU, e.g. `TELUR-STD` | Code 128 |

- Values are ASCII from `[A-Z0-9._-]` (≤ 40 characters), fully covered by Code 128 subset B; a lot
  number is 14 characters. The full UUID is deliberately NOT put in a linear barcode.
- The backend returns `{ "symbology": "CODE128", "value": "…" }`; any Code 128 renderer may be used.
  Products carry no manufacturer barcode (EAN/GTIN) in Project A; the SKU is the product code.

## 3. What a lot identifier means

A DG Way lot number/UUID identifies **DG Way's internal lot** (what DG Way received in one receipt
line), not a supplier production batch. Supplier batch/reference text, when a supplier really provides
one, is stored separately (`supplier_reference`) and is never encoded in the QR or barcode.

## 4. Resolver — `GET /scan/resolve?code=` (bearer)

1. Trim; reject empty, > 128 characters or control characters → `422 SCAN_CODE_INVALID`.
2. Starts with `dgway://`:
   - `v<N>/` with N ≠ 1 → `422 SCAN_VERSION_UNSUPPORTED`;
   - not `<lot|product|sale>/<uuid>` exactly → `422 SCAN_CODE_INVALID`;
   - entity with that id exists → that entity (`matched_by: QR`); else `404 SCAN_CODE_UNKNOWN`.
3. Otherwise plain text (barcode or typed), compared **upper-cased, exact** with
   lot numbers and product SKUs:
   - one match → `LOT` (`matched_by: LOT_NUMBER`) or `PRODUCT` (`matched_by: SKU`);
   - none → `404 SCAN_CODE_UNKNOWN`;
   - both (a SKU equal to a lot number) → `409 SCAN_CODE_AMBIGUOUS` with
     `details.candidate_types: ["LOT", "PRODUCT"]` — always the same answer; scan the QR instead.
4. Read-only: no audit, no idempotency key, no state change.

Unknown codes get one fixed message (`No DG Way record matches this code.`), never echo the scanned
text and do not reveal which entity types exist or were searched. Inactive products still resolve
(`display.is_active: false`) so old labels remain readable. Bare UUIDs and document numbers
(SAL-/PAY-/GR-…) are not resolved here; use `GET /search`.
