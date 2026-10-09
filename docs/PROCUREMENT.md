# Procurement — purchase orders and goods receiving (F4A)

The normal stock-in path is **Purchase Order → Goods Receipt → lots + ledger movements → stock**. Stock adjustment is only for counts, found stock, shrinkage and exceptional corrections; it is never used for routine purchases. A direct purchase (receipt without a PO) still exists in the API but has no app screen.

## Purchase order

```
DRAFT ──confirm──▶ CONFIRMED ──post receipt──▶ PARTIALLY_RECEIVED ──post receipt──▶ RECEIVED
  │                    │                              │                                │
  └──cancel──▶ CANCELLED ◀──cancel (nothing received)  └────────close──▶ CLOSED ◀─close─┘
```

| State | Allowed |
|---|---|
| DRAFT | edit (`PATCH`, `lines` replaces all lines), confirm, cancel |
| CONFIRMED | receive, cancel (no posted receipt) |
| PARTIALLY_RECEIVED | receive the rest, close |
| RECEIVED | close |
| CLOSED, CANCELLED | read-only (terminal; also enforced by trigger `dgway_purchase_order_guard`) |

- **Terms are frozen after DRAFT:** supplier, node, date, currency and lines. Only the received quantity changes, inside receipt posting.
- **CLOSE ≠ CANCEL.**
  - Cancel = the procurement never proceeded.
  - Close = it is finished although part may never arrive. Reason: SUPPLIER_SHORTFALL, PURCHASE_STOPPED or OTHER (OTHER needs a note).
  - Ordered stays as ordered: 100 ordered, 90 received, 10 **unfulfilled** (`lines[].unfulfilled_quantity`).
- **No reopening.** A CLOSED order is never reopened, and its receipts are no longer reversed. A future supplier return must be its own transaction.
- **Lifecycle timestamps:** `confirmed_at/by`, `cancelled_at/by`, `closed_at/by` (audited as `purchase_order.confirmed|cancelled|closed|updated`).

## Goods receipt

- **States:** DRAFT (editable: date, received_at, notes, lines) → POSTED (immutable, never deleted). Also REVERSED (compensating movements, refused if the stock was used or the PO is CLOSED) and CANCELLED (abandoned draft).
- **Several receipts per PO.** Partial receiving is supported. **No over-receipt:** checked at draft time and again under lock at posting.
- **Dispositions per line** (Σ = received): ACCEPT → AVAILABLE, QUARANTINE → QUARANTINE (held), LOSS, RETURN_TO_SUPPLIER → RETURN_PENDING, REGRADE.
- **Actual cost.** It defaults to the agreed PO price and may differ before posting (e.g. 60 KG @ 23,500, then 30 KG @ 23,600). Each receipt and lot keeps its own cost; the PO price is never changed.

## Posting (one transaction)

1. Lock the receipt row (`FOR UPDATE`): a duplicate post waits, then fails with `INVALID_STATUS_TRANSITION` (409).
2. Re-check master data.
3. Lock the PO and its lines, and record received quantities. Over-receipt is refused, also between two receipts posted at the same time, because they serialise on the PO lock.
4. Create one lot per line, plus derived lots for REGRADE.
5. Write the movements and balances (`FOR UPDATE` on the positions).
6. Audit.

Any failure rolls everything back. An `Idempotency-Key` replay returns the stored result without re-executing.

## Purchasing units

- **Conversion master.** `PUT /products/{id}/uom-conversions/{uom}` `{ factor_to_base }` defines "1 unit = factor base units" (permission `catalog.manage_uom_conversion`).
- **Line terms.** PO and receipt lines are written in that unit and copy the conversion. Quantities convert exactly. The base unit cost = price ÷ factor, half-up to 4 decimals (see `INVENTORY_LEDGER.md` §11).

## Permissions

| Permission | Covers |
|---|---|
| `procurement.close` | close a PO |
| `procurement.cancel` | cancel a PO |
| `procurement.reverse` | reverse a goods receipt |
| `catalog.manage_uom_conversion` | define or change purchasing-unit conversions |

Create, edit, confirm, receive and post are routine (active operator). OWNER has `*`.
