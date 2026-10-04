# Domain Model (implemented through Phase A4)

## Shared kernel (`app/Domain/Shared`)

| Type | Kind | Rule it guarantees |
| --- | --- | --- |
| Decimal | VO | no floats; explicit rounding |
| Money | VO | 4-dp, currency-safe, no silent rounding |
| Quantity, Uom | VO | precision per unit, no implicit unit mixing |
| BusinessDate, Clock | VO / port | Asia/Jakarta business day |
| DocumentNumber | VO | `PREFIX-YYMMDD-NNN`, multi-segment prefix |
| Id | VO | UUID identity |
| BusinessCode | VO | upper-case `[A-Z0-9._-]{1,40}`, immutable after create |
| Name, Notes | VO / helper | trimmed, length-bounded |
| PhoneNumber | VO | normalised to E.164 (`0812…` = `+62812…`) |
| EmailAddress | VO | lower-case, validated |
| Address, Coordinates | VO | Indonesian address; only street required; optional lat/long |
| AggregateRoot | base | id + optimistic `version`, `snapshot()` for audit |
| InvariantViolation, EntityNotFound, DuplicateCode, ConcurrentModification | exceptions | stable error codes |

## Catalog (`app/Domain/Catalog`)

**ProductCategory** (aggregate) — `create`, `rename`, `moveUnder` (no cycles, max depth 5),
`activate`, `deactivate(activeChildren, activeProducts)` (refused while in use).

**Product** (aggregate, one row = one SKU) — `create` (category must be active),
`rename`, `recategorize`, `describe`, `changeBaseUom(uom, usedInTransactions)`
(locked after first transaction), `activate`, `deactivate`.

Not on Product, by design: category-specific attributes (future
`product_attributes` per category), prices (pricing/purchasing, with history),
UOM conversions (future product-specific `product_uom_conversions`; never a
global "TRAY = 30").

## Partner (`app/Domain/Partner`)

**BusinessPartner** (aggregate) — one identity with roles:

```
BusinessPartner ── code, name, legal name, party type, phone, email, address, notes, active
   ├── SupplierRole  (terms: tempo days, notes, active)        — no credit limit
   └── CustomerRole  (type, terms: tempo days + credit limit, notes, active)
```

Behaviour: `register` (≥ 1 role), `rename`, `changeLegalName`, `changeContact`,
`relocate`, `changeNotes`, `activate`/`deactivate`, `becomeSupplier`,
`becomeCustomer`, `changeSupplierTerms`, `changeCreditTerms`,
`reclassifyCustomer`, `activateRole`/`deactivateRole`, `isActiveSupplier`,
`isActiveCustomer`.

Invariants: at least one role; a role is assigned once and never removed
(only deactivated); WALK_IN customers are cash only; suppliers have no credit
limit; usable = partner active AND role active.

**PaymentTerms** VO — `termDays` 0–365 (0 = cash/COD) + optional credit limit.
**CustomerType** — RETAIL, UMKM, CATERING, RESTAURANT, RESELLER, CORPORATE, WALK_IN, OTHER.

## Network (`app/Domain/Network`)

**Location** (aggregate) — physical place. `rename`, `relocate`, `changeNotes`,
`activate`, `deactivate(activeNodesHere)` (refused while it hosts active nodes).

**Node** (aggregate) — operational unit with capabilities
(INVENTORY, RECEIVING, FULFILLMENT, PICKUP, DELIVERY), optional location.
`create` (location must be active), `rename`, `relocateTo`,
`redefineCapabilities`/`grant`/`revoke` (≥ 1 capability), `activate(location)`,
`deactivate`, `can(capability)`.

Inventory (A3) will reference **nodes**, never locations directly.

## Procurement (`app/Domain/Procurement`)

- **PurchaseOrder** (aggregate): DRAFT → CONFIRMED → PARTIALLY_RECEIVED →
  RECEIVED, or DRAFT/CONFIRMED → CANCELLED (reason required, not after any
  receipt). `recordReceipt` raises `OVER_RECEIPT`; `reverseReceipt` undoes it
  (a reversed receipt moves the PO back to CONFIRMED / PARTIALLY_RECEIVED).
  Lines: product, ordered qty (base UOM), unit cost, received qty, line total.
- **GoodsReceipt** (aggregate): DRAFT → POSTED → REVERSED, DRAFT → CANCELLED.
  Built against a confirmed PO or as a direct purchase. Each
  **GoodsReceiptLine** has received qty, unit cost, and **Dispositions**
  (ACCEPT, LOSS, RETURN_TO_SUPPLIER, REGRADE→target product) whose sum must
  equal the received qty (`MASS_BALANCE_VIOLATED`). `yieldSummary()` exposes
  raw yield/cost figures; no HPP policy.

## Inventory (`app/Domain/Inventory`)

- **StockMovement** (immutable record): type, `StockKey` (product, node,
  bucket), `Direction`, positive quantity, cost, dates, source document,
  correlation, reversal link. `reverse()` builds the compensating movement;
  a reversal cannot be reversed.
- **StockBalance**: quantity of one position; `apply(movement, policy)`.
- **StockPosting** (domain service): locks positions via the `StockLedger`
  port, applies movements under a `NegativeStockPolicy`
  (`DisallowNegativeStock` → `INSUFFICIENT_STOCK`), appends, saves.
- **InventoryOperation**: LOSS, REGRADE, RECOVERY (only from non-sellable
  buckets), SUPPLIER_RETURN (from RETURN_PENDING), ADJUSTMENT (IN/OUT); reason
  required; produces its movements under one correlation id.

## Sales (`app/Domain/Sales`)

- **Sale** (aggregate): DIRECT origin (ORDER reserved), optional customer
  (null = walk-in), lines, status DRAFT → CONFIRMED → COMPLETED (and back on
  payment reversal), DRAFT → CANCELLED, CONFIRMED/COMPLETED → REVERSED.
  Commands: `confirm(at, CustomerAccount, exposure, payments)`,
  `recordPayment`, `reversePayment`, `recordReturn`, `reverse`, `cancel`.
  Invariants: outstanding = grand total − paid − credited ≥ 0; walk-in / cash
  customer never owes; credit limit; returns ≤ sold; confirmed sale immutable.
  Commands decide first and mutate only when everything passed.
- **SaleLine**: quantity, unit price snapshot, discount, subtotal/total,
  returned quantity/amount; `quoteReturn` (pure) and `takeBack`.
- **Payment** (aggregate, append-only) + **PaymentReversal** (value).
- **CustomerReturn** (aggregate, append-only) + **CustomerReturnLine**.
- **CustomerAccount** (value): walk-in / term days / credit limit captured
  from Partner at confirmation.
- Enums: SaleStatus, SaleOrigin, PaymentMethod, ReturnDisposition.

## Context map

Catalog, Partner, Network, Procurement, Inventory and Sales do not import each
other's classes (enforced by architecture tests); they refer to each other
only by `Id`. The application layer coordinates Procurement → Inventory
and Sales → Inventory (one direction only).

## A5-B additions

| Element | Kind | Invariants |
| --- | --- | --- |
| `Inventory\InventoryLot` | aggregate (append-only) | origin RECEIPT needs supplier + GR line; REGRADE needs a parent and a different product; ADJUSTMENT has no supplier; LEGACY has no quantity/provenance; dates: manufactured ≤ business date, best-before ≥ manufactured; created via `fromReceipt` / `derivedFrom` / `fromAdjustment` only |
| `Inventory\LotOrigin` | enum | RECEIPT, REGRADE, ADJUSTMENT, LEGACY; `creatingMovement()` |
| `Inventory\FifoAllocator` | domain service (pure) | allocates a quantity over lots oldest-first (business date, received at, number); never more than a lot holds; `INSUFFICIENT_STOCK` when the lots cannot cover it |
| `Inventory\LotBalance`, `LotBalanceKey`, `LotStock`, `LotPortion` | value objects | lot balance never negative; key order = lock order |
| `Inventory\StockMovement` | (extended) | carries `lotId`; reversal keeps the original lot (legacy lot for pre-A5-B rows) |
| `Inventory\InventoryOperation` | (extended) | produces one movement per lot portion; regrade creates derived-lot movements; adjustment IN creates a new lot |
| `Sales\CreditLimitOverride` | value object | reason 5–500 chars + approving operator; accepted by `Sale::confirm` only when the limit is actually exceeded (`CREDIT_OVERRIDE_NOT_REQUIRED` otherwise); never changes the limit, never turns cash/walk-in into credit |
| `Procurement\GoodsReceiptLine` | (extended) | optional manufactured / best-before dates (`LOT_DATES_INVALID`) |
