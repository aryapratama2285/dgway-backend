# Architecture

## Layers

```
app/
├── Domain/            Pure PHP. Business concepts and rules. No Laravel.
│   ├── Shared/        Shared kernel: Money, Quantity, Uom, Decimal, BusinessDate,
│   │                  Clock, DocumentNumber, Actor, Id, BusinessCode, Name,
│   │                  PhoneNumber, EmailAddress, Address, AggregateRoot, exceptions
│   ├── Identity/      Role
│   ├── Catalog/       ProductCategory, Product, repository ports
│   ├── Partner/       BusinessPartner (+ SupplierRole, CustomerRole, PaymentTerms)
│   └── Network/       Location, Node, NodeCapability
├── Application/       Use cases + ports (interfaces). No Laravel.
│   ├── Shared/        TransactionManager, AuditRecorder, IdempotencyStore,
│   │                  IdempotentCommandExecutor, DocumentNumberGenerator,
│   │                  ActorContext
│   ├── Identity/      LoginOperator, LogoutOperator, RegisterOperator (+ ports)
│   ├── Catalog/       Create/Update/Activate/DeactivateCategory|Product, queries port
│   ├── Partner/       Create/UpdateBusinessPartner, AssignPartnerRole,
│   │                  ChangePartnerRole, (De)activateBusinessPartner|PartnerRole
│   └── Network/       Create/Update/(De)activateLocation|Node, BootstrapPrimaryNode
├── Infrastructure/    Adapters implementing the ports with Laravel/PostgreSQL
│   ├── Persistence/   Transaction manager, audit, idempotency, sequences,
│   │                  PostgreSQL concurrency-error detector, Repository/ (Db*Repository,
│   │                  RepositorySupport), Query/ (read side), Schema/ helpers,
│   │                  Eloquent record (User only)
│   ├── Authentication/Sanctum tokens, operator directory, actor context
│   └── Time/          SystemClock
├── Http/              Thin adapters: controllers, requests, middleware,
│                      error envelope
├── Console/           Artisan commands (call use cases)
└── Providers/         DomainServiceProvider binds ports → adapters
```

### Dependency rule (enforced by `tests/Architecture/LayerDependencyTest.php`)

| Layer | May depend on |
| --- | --- |
| Domain | itself, `brick/math`; contexts (Catalog/Partner/Network) do not import each other |
| Application | Domain |
| Infrastructure | Application, Domain, Laravel |
| Http / Console | Application, Domain, Laravel |

Controllers may not query the database directly (also tested).

### Request flow

```
HTTP request
  → middleware (JSON, auth:sanctum, active operator, Idempotency-Key)
  → Controller (validate, map input)
  → Application use case
      → TransactionManager::run(
            domain objects enforce invariants
            repositories / adapters write
            AuditRecorder writes audit entry
            IdempotencyStore stores the response )
  → JSON response (error envelope on failure)
```

## Shared kernel decisions

- **Decimal**: wrapper over `brick/math` BigDecimal. Strings or ints only;
  floats are a `TypeError`. Division always takes an explicit scale, half-up.
- **Money**: 4-decimal internal precision (`NUMERIC(19,4)`), currency-aware
  (IDR default), rejects > 4 decimals instead of silently rounding. Rounding to
  whole Rupiah is an explicit call.
- **Quantity**: decimal + `Uom`; precision checked against the unit
  (KG = 3 decimals, PCS/IKAT = 0). Units are never mixed implicitly.
- **Uom**: open value object (code + precision), backed by `uoms` reference
  table. No KG↔PCS conversion for eggs (decision D1).
- **BusinessDate**: the Asia/Jakarta calendar day of an instant. Timestamps are
  stored in UTC; reports group by business date.
- **DocumentNumber**: `PREFIX-YYMMDD-NNN`, multi-segment prefixes allowed
  (`EGG`, `EGG-BDG`, `BWM`), sequence widens past 999. Database identity is
  always UUID; the number is a display identifier (decision D14).

## Transaction boundary

`TransactionManager::run()` — outermost call opens a PostgreSQL transaction
(with up to 3 retries on deadlock / serialization failure); nested calls join
it. Audit records, idempotency records and document numbers are written on the
same connection, so they commit or roll back with the business change.

## Idempotency

Final state-changing commands carry an `Idempotency-Key` header (UUID made by
the app per user action). `IdempotentCommandExecutor`:

1. `INSERT … ON CONFLICT DO NOTHING` on unique `(actor_scope, idempotency_key)`
   — a concurrent request with the same key **waits** on the row lock;
2. if the key exists: same command+payload hash → replay stored response
   (`Idempotent-Replayed: true`), different → `409 IDEMPOTENCY_KEY_REUSED`;
3. otherwise execute the command and store its response, all in one
   transaction. A failed attempt leaves no key, so the retry executes.

## Audit

`audit_logs` is append-only: a PostgreSQL trigger (`dgway_forbid_mutation`)
rejects `UPDATE` and `DELETE`. The same function will guard
`inventory_movements` and other ledgers in later phases.

## Authentication & authorization

- Laravel Sanctum bearer tokens (hash stored, plain token shown once),
  30-day expiry, one token per device.
- Passwords: bcrypt via Laravel's `hashed` cast; no plaintext anywhere; no
  default accounts; operators are created from the console.
- Basic authorization: token must belong to an **active** operator whose role
  can operate. Only `OWNER` exists now; the `role` column and `Role` enum are
  ready for ADMIN/WAREHOUSE/… later (not implemented).
- Login is rate limited (5/min per email, 10/min per IP) and every success or
  failure is audited.
- **Permission-guarded actions (A5-B)** go through the `Authorizer` port
  (`App\Application\Shared\Security`) with a `Permission` enum; the
  `RoleBasedAuthorizer` maps role → permissions from
  `config/dgway.php authorization.role_permissions` (OWNER = `*`; ADMIN,
  CASHIER, WAREHOUSE, PURCHASING, DELIVERY listed with no permissions — the
  extension point, not implemented roles). No use case or controller checks
  `role == OWNER`. Denial → 403 `ACCESS_DENIED`. First guarded action:
  `sales.override_credit_limit` (re-checked inside the confirmation transaction).

## Master data patterns (A2)

- **Rich aggregates**: state changes only through intent methods
  (`rename`, `changeCreditTerms`, `deactivate`, …) which return whether
  anything changed; no public setters.
- **Repositories** (ports in Domain, `Db*Repository` in Infrastructure) map
  aggregates to rows with the query builder; Eloquent is not used for
  business entities. `get` / `getForUpdate` (row lock) / `add` / `save`.
- **Uniqueness under concurrency**: INSERT relies on the UNIQUE index; a
  `23505` becomes `DuplicateCode` (409). Proven with two OS processes.
- **Optimistic concurrency**: `version` column, `UPDATE … WHERE version = ?`;
  optional `expected_version` from the client.
- **Read side**: `CatalogQueries`, `PartnerQueries`, `NetworkQueries` ports
  for filtered/paged lists, separate from repositories.
- **Audit**: `AuditTrail` records created snapshots and, for changes, only
  the fields that changed (before → after).
- **Retry policy**: `PostgresConcurrencyErrorDetector` makes the transaction
  manager retry SQLSTATE 40001 (serialization) and 40P01 (deadlock).

## Procurement & inventory (A3)

- **Contexts**: `Procurement` (PurchaseOrder, GoodsReceipt) and `Inventory`
  (StockMovement, StockBalance, StockPosting, InventoryOperation). The two
  domains do not import each other or master-data contexts; the application
  layer coordinates (`PostGoodsReceipt` → `ReceiptMovementPlanner` →
  `InventoryPoster`). Inventory application code never imports Procurement
  (one-way dependency, enforced by architecture tests).
- **One transaction per command**: document + PO + ledger movements +
  balances + audit + idempotency key commit or roll back together.
- **Locking order inside a posting**: receipt row (`FOR UPDATE`) → PO row →
  balance rows sorted by key. Double posting / double reversal therefore
  serialise on the document row; stock decrements serialise on the balance
  rows.
- **Status machines in the domain** (`PurchaseOrderStatus::canTransitionTo`,
  `GoodsReceiptStatus`), mirrored by DB triggers. Invalid transitions →
  `409 INVALID_STATUS_TRANSITION`; insufficient stock → `409 INSUFFICIENT_STOCK`.
- **Master data guard** (`MasterDataGuard`): new transactions require an
  active supplier role, an active node with the needed capability
  (RECEIVING + INVENTORY for receipts, INVENTORY for operations), an active
  product, and the product's base UOM. Posting re-validates. Reads never go
  through the guard, so history stays readable after deactivation.
- **No descriptive snapshots** on documents: documents store ids; master
  records are never deleted and codes/SKUs are immutable, so the reference is
  stable. (Names may change; that is accepted for an internal tool.)
- Details: [docs/INVENTORY_LEDGER.md](docs/INVENTORY_LEDGER.md).

## Sales & receivables (A4)

- **Context** `Sales` (Sale, SaleLine, Payment, PaymentReversal,
  CustomerReturn). It imports no other context; the application layer
  coordinates Sales → Inventory (`InventoryPoster`, `LedgerReversal`) and
  reads customer terms from Partner into a `CustomerAccount` value.
  Inventory and Procurement never import Sales (architecture tests).
- **Aggregate boundaries**: Sale is the consistency boundary for the money of
  one sale; Payment and CustomerReturn are separate append-only aggregates
  created through the Sale. Receivable is derived, never stored as an
  editable figure.
- **Locks**: sale row → customer credit row (only when credit is used) →
  stock rows. Credit-limit races are prevented by the customer lock.
- **409 vs 422**: errors implementing `ConflictsWithCurrentState`
  (`INVALID_STATUS_TRANSITION`, `INSUFFICIENT_STOCK`, `PAYMENT_EXCEEDS_OUTSTANDING`,
  `CREDIT_LIMIT_EXCEEDED`, `RETURN_EXCEEDS_SOLD`, `PAYMENT_ALREADY_REVERSED`,
  `SALE_HAS_PAYMENTS`, …) answer 409 — the request may succeed in another
  state. Policy / input violations answer 422.
- Details: [docs/SALES_AND_RECEIVABLES.md](docs/SALES_AND_RECEIVABLES.md).

## Data evolution (A5 addendum)

Project A data is "Generation 1" of the DG Way platform: stable UUIDv7
identities, one owning context per table (`config/data_contract.php`),
append-only ledgers and financial history, forward-only migrations in
production, and reconciliation/trace tooling. Audit entries written inside an
idempotent command carry its `idempotency_key` and `command` (`AuditScope`).
See docs/DATA_EVOLUTION_STRATEGY.md.

## Lots and operational readiness (A5-B)

- Lots are a dimension of the ledger (`stock_movements.lot_id` + projection
  `stock_lot_balances`); `StockKey` is unchanged. Allocation is server-side
  FIFO in `Application\Inventory\LotAllocator` (position locks first, then
  lot rows) and the pure `Domain\Inventory\FifoAllocator`. Sales find the lots
  to return into through the `SaleLotConsumption` port. docs/BATCH_LOT_MODEL.md.
- `Application\Operations` is a cross-context **read** layer (dashboard,
  activity, search, defaults, bootstrap orchestration); it writes nothing
  except through the existing use cases (bootstrap).
- Every API request gets an `X-Request-Id` (`AssignRequestId` middleware): it is
  shared with all log lines (`Log::shareContext`) and pushed into the
  `AuditScope`, next to the idempotency key; `ApiController::command` logs one
  `api.command` line with the resulting document id.
- `/health/ready` and `dgway:doctor` cover readiness and production
  configuration; docs/DEPLOYMENT.md, docs/BACKUP_RESTORE.md.

## Printing & scanning (A5-C)

`Application\Printing` builds device-neutral print documents (`PrintDocuments`), resolves scanned codes
(`ScanResolver`, `IdentifierCodec`) and manages `PrintSettings` through ports implemented in
`Infrastructure\Printing`. No ESC/POS, Bluetooth or printer configuration exists in the backend;
docs/PRINTING_SCANNING_CONTRACT.md. Lot upstream facts (collection date, supplier reference) are plain
optional value fields on `GoodsReceiptLine` and `InventoryLot` — no new aggregate.

## Not yet in place (by design, later phases)

Printing/scanning (A5-C), customer orders / fulfillment / delivery, HPP policy
and reporting beyond the owner dashboard, CSV export, Flutter app.
