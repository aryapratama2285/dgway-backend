# API error codes — v1

Every error response uses ONE envelope (see API_CONTRACT_V1.md §Errors):

```json
{ "error": { "code": "INSUFFICIENT_STOCK", "message": "…human text, may change…", "details": { "…": "…" } } }
```

- **`code` is the contract.** The Flutter app branches on `code`, never on `message` (messages are for people and may be reworded or translated).
- `details` is optional (present only when there is something structured to add, e.g. `fields` for validation, `outstanding`, `available_credit`, `permission`, `reference`).
- HTTP status follows the class of problem, so a client that does not know a new code still behaves sensibly:

| HTTP | Meaning | Client reaction |
| --- | --- | --- |
| 400 | Malformed transport input (e.g. bad Idempotency-Key format) | Bug in the app: fix the request |
| 401 | Not authenticated (`UNAUTHENTICATED`, `INVALID_CREDENTIALS`) | Go to login |
| 403 | Authenticated but not allowed (`ACCESS_DENIED`, `OPERATOR_INACTIVE`) | Show "not allowed", do not retry |
| 404 | Entity / route does not exist (`NOT_FOUND`) | Refresh the list |
| 409 | Valid request that conflicts with the CURRENT state (stock, status, version, credit, key reuse) | Reload the document, show the reason; retrying the same request will fail again |
| 422 | Request breaks a business rule or validation (`VALIDATION_FAILED` + `details.fields`, or a rule code) | Show the message next to the field / form |
| 428 | `Idempotency-Key` header missing on a command | Bug in the app: always send a key |
| 429 | Rate limited (`TOO_MANY_REQUESTS`) | Back off and retry later |
| 500 | `INTERNAL_ERROR`; nothing was saved; `details.reference` = server log id | Safe to retry with the SAME Idempotency-Key |
| 503 | Health/readiness: not ready | Retry later |

Retry rule: a network failure or 5xx may be retried **with the same Idempotency-Key** (see MOBILE_IDEMPOTENCY.md). A 4xx will not change by retrying.

The test `MobileApiContractTest::test_every_error_code_is_documented` scans `app/` and fails when a code is missing from this file.

## Codes by context

The "meaning" column is taken from the server message (‹…› = value filled in at runtime).


### HTTP / transport

| Code | HTTP | Meaning |
| --- | --- | --- |
| `IDEMPOTENCY_KEY_INVALID` | 400 | The Idempotency-Key header is not a valid key (use a UUID). |
| `FORBIDDEN` | 403 | Anda tidak berwenang melakukan aksi ini. |
| `IDEMPOTENCY_KEY_REQUIRED` | 428 | Header Idempotency-Key wajib untuk transaksi ini. |
| `INTERNAL_ERROR` | 500 | Terjadi kesalahan server. Transaksi tidak disimpan. |
| `NOT_FOUND` | 404 | The entity or route does not exist (`details.entity`, `details.id` for domain lookups). |
| `OPERATOR_INACTIVE` | 403 | Akun operator tidak aktif atau tidak berwenang. |
| `TOO_MANY_REQUESTS` | 429 | Terlalu banyak percobaan. Coba lagi sebentar lagi. |
| `UNAUTHENTICATED` | 401 | Silakan login terlebih dahulu. |
| `VALIDATION_FAILED` | 422 | Data yang dikirim tidak valid. |

### Shared

| Code | HTTP | Meaning |
| --- | --- | --- |
| `ACCESS_DENIED` | 403 | Authenticated, but the role lacks the permission (details.permission). |
| `ACTOR_INVALID` | 422 | Operator id must be a UUID. |
| `ADDRESS_FIELD_TOO_LONG` | 422 | Address ‹…› must be at most ‹…› characters. |
| `ADDRESS_STREET_REQUIRED` | 422 | Address street is required. |
| `BUSINESS_DATE_INVALID` | 422 | Invalid business date '‹…›'. |
| `CODE_INVALID` | 422 | ‹…› '‹…›' is invalid. Use 1–40 letters, digits, '-', '_' or '.', starting with a letter or digit. |
| `CONCURRENT_MODIFICATION` | 409 | expected_version does not match the current version: reload and retry. |
| `CURRENCY_INVALID` | 422 | Invalid currency code '‹…›'. |
| `CUSTOMER_NOT_USABLE` | 422 | ‹…› is not an active customer. |
| `DECIMAL_INVALID` | 422 | '‹…›' is not a valid decimal number. |
| `DIVISION_BY_ZERO` | 422 | Cannot divide by zero. |
| `DOCUMENT_NUMBER_INVALID` | 422 | Invalid document number '‹…›'. |
| `DOCUMENT_PREFIX_INVALID` | 422 | Invalid document prefix '‹…›'. Use 1–3 uppercase segments separated by '-', e.g. SO or EGG-BDG. |
| `DOCUMENT_SEQUENCE_INVALID` | 422 | Document sequence must start at 1. |
| `DUPLICATE_CODE` | 409 | A master record with this business code already exists. |
| `EMAIL_INVALID` | 422 | '‹…›' is not a valid email address. |
| `IDEMPOTENCY_KEY_REUSED` | 409 | The Idempotency-Key was already used for a DIFFERENT request body/command. |
| `ID_INVALID` | 422 | '‹…›' is not a valid identifier. |
| `LATITUDE_INVALID` | 422 | Latitude must be between -90 and 90. |
| `LONGITUDE_INVALID` | 422 | Longitude must be between -180 and 180. |
| `MONEY_NEGATIVE` | 422 | ‹…› must not be negative. |
| `MONEY_PRECISION_EXCEEDED` | 422 | Money supports at most ‹…› decimal places, got '‹…›'. |
| `NAME_REQUIRED` | 422 | ‹…› is required. |
| `NAME_TOO_LONG` | 422 | ‹…› must be at most ‹…› characters. |
| `NODE_NOT_USABLE` | 422 | Node ‹…› is inactive or lacks capability ‹…›. |
| `NOTES_TOO_LONG` | 422 | Notes must be at most ‹…› characters. |
| `PHONE_INVALID` | 422 | '‹…›' is not a valid phone number. |
| `POSTAL_CODE_INVALID` | 422 | Indonesian postal code must be 5 digits. |
| `PRODUCT_INACTIVE` | 422 | Product ‹…› is inactive. |
| `QUANTITY_NEGATIVE` | 422 | ‹…› must not be negative. |
| `QUANTITY_NOT_POSITIVE` | 422 | ‹…› must be greater than zero. |
| `QUANTITY_PRECISION_EXCEEDED` | 422 | Unit ‹…› allows ‹…› decimal place(s), got '‹…›'. |
| `SUPPLIER_NOT_USABLE` | 422 | ‹…› is not an active supplier. |
| `UOM_CODE_INVALID` | 422 | Invalid unit of measure code '‹…›'. |
| `UOM_NOT_BASE` | 422 | ‹…› is stocked in ‹…›; '‹…›' would need a conversion, which is not defined. |
| `UOM_PRECISION_INVALID` | 422 | UOM precision must be between 0 and ‹…›. |

### Identity

| Code | HTTP | Meaning |
| --- | --- | --- |
| `INVALID_CREDENTIALS` | 401 | Wrong email or password (never says which). |

### Catalog

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CATEGORY_CYCLE` | 422 | A category cannot be its own parent. |
| `CATEGORY_INACTIVE` | 422 | Category ‹…› is inactive. |
| `CATEGORY_IN_USE` | 422 | Deactivate or move the active sub-categories and products first. |
| `CATEGORY_PARENT_INACTIVE` | 422 | Parent category ‹…› is inactive. |
| `CATEGORY_TOO_DEEP` | 422 | Category hierarchy may be at most ‹…› levels deep. |
| `DESCRIPTION_TOO_LONG` | 422 | Description must be at most ‹…› characters. |
| `PRODUCT_UOM_LOCKED` | 422 | Base UOM of ‹…› cannot change after it has been used in transactions. Create a new SKU instead. |
| `PRODUCT_CLASSIFICATION_LOCKED` | 422 | Classification of ‹…› cannot change after it has been used in transactions. Create a new SKU instead. |
| `UOM_UNKNOWN` | 422 | Unit of measure '‹…›' does not exist or is inactive. |

### Partner

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CUSTOMER_TYPE_INVALID` | 422 | Unknown customer type '‹…›'. |
| `PARTNER_ROLE_REQUIRED` | 422 | A business partner needs at least one role (SUPPLIER and/or CUSTOMER). |
| `PARTY_TYPE_INVALID` | 422 | party_type must be INDIVIDUAL or ORGANIZATION. |
| `ROLE_ALREADY_ASSIGNED` | 422 | ‹…› is already a supplier. |
| `ROLE_NOT_ASSIGNED` | 422 | ‹…› is not a supplier. |
| `SUPPLIER_CREDIT_LIMIT_NOT_APPLICABLE` | 422 | A credit limit applies to customers, not suppliers. |
| `TERM_DAYS_INVALID` | 422 | Payment term must be between 0 and ‹…› days. |
| `WALK_IN_MUST_BE_CASH` | 422 | Walk-in customers are cash only (no tempo, no credit limit). |

### Network

| Code | HTTP | Meaning |
| --- | --- | --- |
| `LOCATION_HAS_ACTIVE_NODES` | 422 | Deactivate or move the active nodes at this location first. |
| `LOCATION_INACTIVE` | 422 | Location ‹…› is inactive. |
| `NODE_CAPABILITY_INVALID` | 422 | Unknown node capability '‹…›'. |
| `NODE_CAPABILITY_REQUIRED` | 422 | A node needs at least one capability. |

### Procurement

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CANCEL_REASON_REQUIRED` | 422 | A cancellation reason is required. |
| `CURRENCY_MISMATCH` | 422 | All lines of a sale must use the same currency. |
| `DISPOSITION_NOT_FOR_RECEIPT` | 422 | RECOVERY applies to stock that is already non-sellable; use the inventory recovery operation. |
| `DISPOSITION_REQUIRED` | 422 | Line ‹…›: every received quantity needs a disposition (e.g. ACCEPT). |
| `DISPOSITION_TARGET_INVALID` | 422 | ‹…› |
| `DISPOSITION_TYPE_INVALID` | 422 | Unknown disposition type '".(‹…› ?? ' |
| `GR_DUPLICATE_PRODUCT` | 422 | Each product may appear only once per receipt. |
| `GR_LINES_REQUIRED` | 422 | A goods receipt needs at least one line. |
| `GR_PO_LINE_REQUIRED` | 422 | Line ‹…›: a receipt against a purchase order must reference its PO line. |
| `GR_PO_LINE_WITHOUT_PO` | 422 | A direct receipt cannot reference purchase order lines. |
| `GR_PO_MISMATCH` | 422 | Receipt supplier and node must match the purchase order. |
| `LINE_NO_DUPLICATE` | 422 | Line numbers must be unique. |
| `LINE_NO_INVALID` | 422 | Line numbers start at 1. |
| `MASS_BALANCE_VIOLATED` | 422 | Line ‹…›: received ‹…› but dispositions total ‹…›. Every unit must be accounted for. |
| `OVER_RECEIPT` | 422 | Receiving ‹…› exceeds the open quantity ‹…› of PO line ‹…›. Over-receipt is not allowed. |
| `PO_DUPLICATE_PRODUCT` | 422 | Each product may appear only once per purchase order. |
| `PO_HAS_RECEIPTS` | 422 | A purchase order with posted receipts cannot be cancelled. Reverse the receipts first. |
| `PO_LINES_REQUIRED` | 422 | A purchase order needs at least one line. |
| `PO_LINE_NOT_FOUND` | 422 | Purchase order line not found on this order. |
| `PO_NOT_RECEIVABLE` | 422 | Purchase order ‹…› is ‹…›; only CONFIRMED or PARTIALLY_RECEIVED orders can be received. |
| `REVERSAL_REASON_REQUIRED` | 422 | A reversal reason is required. |
| `UNIT_COST_REQUIRED` | 422 | Line ".(‹…› + 1 |
| `UNRECEIVE_EXCEEDS_RECEIVED` | 422 | Cannot reverse more than was received on PO line ‹…›. |

### Inventory

| Code | HTTP | Meaning |
| --- | --- | --- |
| `BUCKET_INVALID` | 422 | Unknown stock bucket '‹…›'. |
| `DIRECTION_INVALID` | 422 | direction must be IN or OUT. |
| `INSUFFICIENT_STOCK` | 409 | Not enough stock in the bucket (details: available, requested, lot_id when a lot was short). |
| `LOT_ALLOCATION_MISMATCH` | 422 | Lot portions total ‹…›, operation is ‹…›. |
| `LOT_DATES_INVALID` | 422 | Line ‹…›: best-before date cannot be before the manufactured date. |
| `LOT_LINEAGE_INVALID` | 422 | A derived lot must change the product. |
| `LOT_REQUIRED` | 422 | An adjustment IN creates a new lot. |
| `MOVEMENT_DIRECTION_INVALID` | 422 | ‹…› cannot move stock ‹…›. |
| `MOVEMENT_LOT_REQUIRED` | 422 | Every new stock movement must name its lot. |
| `MOVEMENT_POSITION_MISMATCH` | 422 | Movement does not belong to this lot position. |
| `MOVEMENT_REVERSAL_LINK_INVALID` | 422 | Only REVERSAL movements reference a reversed movement, and they must. |
| `MOVEMENT_UOM_NOT_BASE` | 422 | Movements must be in the product base UOM. |
| `NO_MOVEMENTS` | 422 | Nothing to post. |
| `REASON_REQUIRED` | 422 | A return reason is required. |
| `RECOVERY_FROM_SELLABLE` | 422 | Recovery moves non-sellable stock (RETURN_PENDING or QUARANTINE) back to AVAILABLE. |
| `REGRADE_TARGET_REQUIRED` | 422 | Regrade needs a target product. |
| `REGRADE_TO_SAME_PRODUCT` | 422 | Line ‹…›: a regrade must change the product. |
| `REVERSAL_OF_REVERSAL` | 422 | A reversal cannot itself be reversed; post a new transaction instead. |
| `SOURCE_DOCUMENT_TYPE_INVALID` | 422 | Invalid source document type '‹…›'. |
| `UOM_MISMATCH` | 422 | Line ‹…› was sold in ‹…›. |

### Sales

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CREDIT_LIMIT_EXCEEDED` | 409 | Credit limit ‹…› exceeded: already owed ‹…›, this sale adds ‹…›. |
| `CREDIT_NOT_ALLOWED` | 422 | (‹…› |
| `CREDIT_OVERRIDE_NOT_REQUIRED` | 409 | This sale does not exceed the credit limit; confirm it normally (an override is recorded only when it is actually used). |
| `CREDIT_OVERRIDE_REASON_REQUIRED` | 422 | A credit limit override needs a reason of ‹…›–‹…› characters. |
| `DISCOUNT_EXCEEDS_SUBTOTAL` | 422 | Line ‹…›: discount ‹…› is more than the line subtotal ‹…›. |
| `INVALID_STATUS_TRANSITION` | 409 | Sale cannot go from ‹…› to ‹…›. |
| `PAYMENT_ALREADY_REVERSED` | 409 | Payment ‹…› is already reversed. |
| `PAYMENT_AMOUNT_INVALID` | 422 | A payment amount must be positive. |
| `PAYMENT_EXCEEDS_OUTSTANDING` | 409 | Payments ‹…› are more than the sale total ‹…›. Deposits/prepayments are not supported. |
| `PAYMENT_METHOD_INVALID` | 422 | Unknown refund method '‹…›'. |
| `PAYMENT_NOT_OF_SALE` | 422 | The payment belongs to another sale. |
| `REFUND_METHOD_REQUIRED` | 422 | This return is worth ‹…› but only ‹…› is still owed; ‹…› must be refunded — say how (refund_method). |
| `RETURN_DISPOSITION_INVALID` | 422 | Disposition must be RETURN_TO_AVAILABLE, RETURN_TO_QUARANTINE or LOSS. |
| `RETURN_EXCEEDS_SOLD` | 409 | Line ‹…›: only ‹…› ‹…› can still be returned (sold ‹…›, returned ‹…›). |
| `RETURN_LINES_REQUIRED` | 422 | A return needs at least one line. |
| `RETURN_LINE_DUPLICATE` | 422 | Each sale line may appear only once per return. |
| `SALE_HAS_PAYMENTS` | 409 | Sale ‹…› has payments of ‹…›. Reverse them first, or reverse the sale with reverse_payments=true (refund). |
| `SALE_HAS_RETURNS` | 409 | Sale ‹…› already has customer returns; record a return for the rest instead of reversing. |
| `SALE_LINES_REQUIRED` | 422 | A sale needs at least one line. |
| `SALE_LINE_NOT_FOUND` | 422 | Line ‹…› does not belong to sale ‹…›. |
| `SALE_NOT_PAYABLE` | 409 | Sale ‹…› is ‹…›; payments are accepted only while something is owed. |
| `SALE_NOT_RETURNABLE` | 409 | Sale ‹…› is ‹…›; only confirmed sales accept returns. |
| `WALK_IN_MUST_BE_PAID` | 409 | A walk-in sale must stay fully paid; reverse the whole sale (with reverse_payments) instead of a single payment. |

### Operations

| Code | HTTP | Meaning |
| --- | --- | --- |
| `BOOTSTRAP_PASSWORD_REQUIRED` | 422 | An owner needs a password supplied by you (STDIN or prompt). There is no default password. |
| `BOOTSTRAP_UOM_MISSING` | 422 | Base units missing: '.implode(',  |

## A5-B additions

| Code | HTTP | When |
| --- | --- | --- |
| `ACCESS_DENIED` | 403 | Permission-guarded action (today: `sales.override_credit_limit`) by a role without it. `details.permission`. |
| `CREDIT_OVERRIDE_REASON_REQUIRED` | 422 | Credit override without a 5–500 character reason (also caught earlier as `VALIDATION_FAILED`). |
| `CREDIT_OVERRIDE_NOT_REQUIRED` | 409 | Override requested for a sale that does not exceed the limit — confirm normally. |
| `LOT_DATES_INVALID` | 422 | best_before_date before manufactured_date, or manufactured_date after the receipt date. |
| `LOT_REQUIRED`, `LOT_ALLOCATION_MISMATCH`, `MOVEMENT_LOT_REQUIRED`, `LOT_LINEAGE_INVALID` | 422 | Internal lot-allocation guards; never expected from a correct client — report as a bug. |
| `INSUFFICIENT_STOCK` | 409 | Unchanged meaning; `details.lot_id` is added when one lot was short during allocation (not expected: allocation is per position). |
| `BOOTSTRAP_UOM_MISSING`, `BOOTSTRAP_PASSWORD_REQUIRED` | — | Console only (`dgway:bootstrap`), never over HTTP. |


## A5-C additions (lot upstream facts, printing, scanning)

| Code | HTTP | When |
| --- | --- | --- |
| `COLLECTION_DATE_INVALID` | 422 | `collection_date` after the receipt business date (`details.rule = not_after_receipt`) or before the stated `manufactured_date` (`not_before_manufactured`). |
| `SUPPLIER_REFERENCE_TOO_LONG` | 422 | `supplier_reference` longer than 60 characters (usually caught earlier as `VALIDATION_FAILED`). |
| `BUSINESS_NAME_REQUIRED` | 422 | Print settings with an empty business name (HTTP trimming normally reports `VALIDATION_FAILED` first; domain guard). |
| `PRINT_FIELD_TOO_LONG` | 422 | A print-settings text field exceeds its limit (`details.field`, `details.max`). |
| `RECEIPT_WIDTH_INVALID` | 422 | `receipt_width_mm` not 58 or 80 (usually `VALIDATION_FAILED` first). |
| `SCAN_CODE_INVALID` | 422 | Empty, longer than 128 characters, control characters, or a malformed `dgway://` code (unknown entity, bad UUID, extra path). |
| `SCAN_VERSION_UNSUPPORTED` | 422 | `dgway://v<N>/…` with N ≥ 2: the code was made by a newer contract version — update the app. |
| `SCAN_CODE_UNKNOWN` | 404 | No lot/product/sale matches. Fixed message; the scanned text is never echoed and the response does not reveal which entity types were searched. |
| `SCAN_CODE_AMBIGUOUS` | 409 | Text matches more than one entity type (e.g. a product SKU equal to a lot number). `details.candidate_types` (types only, no data). Scan the QR code instead — QR codes are never ambiguous. |
| `ACCESS_DENIED` | 403 | Also for `PATCH /print/settings` without permission `settings.manage_print`. |

## F3 additions (inventory operations, internal consumption, reorder points)

| Code | HTTP | When |
| --- | --- | --- |
| `REASON_CODE_INVALID` | 422 | `reason_code` not allowed for the operation: internal consumption needs PACKING, LABELING, DELIVERY, ADMIN, SAMPLE or OTHER; an adjustment accepts COUNT_CORRECTION, FOUND_STOCK, SHRINKAGE, OTHER (or none). Usually caught first as `VALIDATION_FAILED`. |
| `REFERENCE_TEXT_INVALID` | 422 | `reference_text` of a consumption longer than 60 characters (domain guard; usually `VALIDATION_FAILED` first). |
| `ADJUSTMENT_NOT_REQUIRED` | 422 | Counted adjustment whose `counted_quantity` equals the AVAILABLE stock held by the system (`details.counted_quantity`, `details.system_quantity`). Nothing is written. |
| `STOCK_CHANGED_SINCE_COUNT` | 409 | Counted adjustment with `expected_available` different from the AVAILABLE stock found under lock: stock moved since the operator counted. Reload and count again (`details.expected_available`, `details.current_available`). |
| `COUNT_SNAPSHOT_INCOMPLETE`, `COUNT_SNAPSHOT_MISMATCH` | 422 | Internal guards of a counted adjustment (counted/system quantities must both be present and their difference must equal the posted delta). Never expected from a correct client — report as a bug. |
| `BASE_UOM_LOCKED_BY_REORDER_POINT` | 422 | `PATCH /products/{id}` changes `base_uom` while the product has an active reorder point (the threshold is expressed in that unit). Clear the reorder point (`min_quantity: null`) first. Also enforced by a database trigger. |
| `ACCESS_DENIED` | 403 | Also for `POST /inventory/adjustments` (both modes) by a role without `inventory.adjust`. |
| `INSUFFICIENT_STOCK` | 409 | Also for `POST /inventory/consumptions` when the quantity exceeds AVAILABLE at the node (nothing written). |

`INVENTORY_ADJUSTMENT` and `INTERNAL_CONSUMPTION` are activity-feed / operation **types** (data), not error codes.

## F4A additions (procurement lifecycle & purchasing units)

| Code | HTTP | When |
| --- | --- | --- |
| `UOM_CONVERSION_MISSING` | 422 | A PO or goods-receipt line uses a unit other than the product's base unit and the product has no ACTIVE conversion for it (`details.field`, `details.base_uom`). Define it with `PUT /products/{id}/uom-conversions/{uom}`. Replaces `UOM_NOT_BASE` for purchasing documents only. |
| `UOM_CONVERSION_INVALID` | 422 | Conversion factor ≤ 0 or with more than 6 decimals; the unit is the product's base unit; or a purchasing quantity whose base equivalent would need more decimals than the base unit allows (never rounded). |
| `CLOSE_REASON_REQUIRED` | 422 | `POST /purchase-orders/{id}/close` with an unknown reason, or reason `OTHER` without a `note`. |
| `INVALID_STATUS_TRANSITION` | 409 | Also: closing an order that is not PARTIALLY_RECEIVED / RECEIVED; editing (`PATCH`) a purchase order or goods receipt that is no longer DRAFT (`details.action = "edit"`); reversing a receipt of a CLOSED order. |
| `GR_PO_MISMATCH` | 422 | Unchanged code; `details.field` now says which part differs: `supplier_id`, `node_id` or `lines.N.product_id`. |
| `CURRENCY_MISMATCH` | 422 | Also when a receipt's currency differs from its purchase order's. |
| `OVER_RECEIPT` | 422 | Unchanged; `details` now carry `field`, `open`, `requested`, `uom` when raised at draft time. |
| `PRODUCT_UOM_LOCKED` | 422 | Also when `PATCH /products/{id}` changes `base_uom` while ACTIVE purchasing-unit conversions exist (also enforced by a trigger). |
| `ACCESS_DENIED` | 403 | Also for close (`procurement.close`), cancel (`procurement.cancel`), goods-receipt reversal (`procurement.reverse`) and conversions (`catalog.manage_uom_conversion`) without the permission. |

`PURCHASE_ORDER_CONFIRMED`, `PURCHASE_ORDER_CLOSED` and `PURCHASE_ORDER_CANCELLED` are activity-feed **types** (data), not error codes.
