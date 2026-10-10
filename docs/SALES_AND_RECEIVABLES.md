# Sales, payments and receivables (Phase A4)

Closes the loop PROCUREMENT → RECEIVING → INVENTORY → **SALE → PAYMENT → RECEIVABLE**.
Not a marketplace, not a general ledger.

## 1. Aggregates and why they are separate

| Aggregate | Owns | Why its own boundary |
| --- | --- | --- |
| **Sale** (+ SaleLine) | lines with price snapshot, totals, status, running sums `paid` / `credited`, due date | consistency boundary for the money of ONE sale: every change of what is owed goes through it, under a row lock on the sale |
| **Payment** | one receipt of money (method, amount, time, reference) + optional PaymentReversal | own identity and immutable history; many per sale; created only via `Sale::recordPayment()` because "never more than outstanding" is the sale's invariant |
| **CustomerReturn** | goods brought back per sale line, value, credit/refund split | own document (RET), append-only; created only via `Sale::recordReturn()` because "never more than sold − returned" is the sale's invariant |
| **Receivable** | — (not stored) | derived: `outstanding = grand_total − Σ valid payments − Σ return credits` |

Stock is not part of any sales aggregate: the application posts SALE /
CUSTOMER_RETURN / REVERSAL movements through the A3 ledger
(`InventoryPoster`, `LedgerReversal`) in the same transaction.

## 2. Sale state machine

| From \ To | CONFIRMED | COMPLETED | CANCELLED | REVERSED |
| --- | --- | --- | --- | --- |
| DRAFT | confirm (something owed) | confirm (fully paid at the counter) | cancel | — |
| CONFIRMED | — | last payment / return credit settles it | — | reverse |
| COMPLETED | a payment is reversed | — | — | reverse |
| CANCELLED, REVERSED | terminal | | | |

- **Inventory consequence happens at confirm** (SALE −q AVAILABLE per line).
- A confirmed sale is never edited (domain + DB trigger). Corrections are
  customer returns, payment reversals or a full sale reversal.
- COMPLETED means *final and fully settled*; it is driven by money, so
  `sale.completed` is audited when it happens.
- Order-based sales (future): `origin = ORDER` + `order_reference_id`; the
  lifecycle after confirmation is identical. A4 creates DIRECT sales only.

## 3. Totals and rounding

- `line subtotal = round(quantity × unit_price, 4)` half-up (A1 Money);
  `line total = subtotal − line discount`, `0 ≤ discount ≤ subtotal`.
- `subtotal = Σ line subtotals`, `discount_total = Σ discounts`,
  `grand_total = subtotal − discount_total ≥ 0`.
- Stored on the header for querying, guarded by CHECKs
  (`grand_total = subtotal − discount_total`) and by a trigger that compares
  the header with Σ lines when the sale leaves DRAFT.
- Example: 35 KG × 26 000 = 910 000.0000; 100 × 25 500 = 2 550 000.0000.
- The unit price is a snapshot on the line; there is no `products.sale_price`
  (and no price master in A4).

## 4. Payments

- Methods: CASH, BANK_TRANSFER, QRIS (manual, no gateway). Extensible: enum
  case + CHECK migration.
- Number `PAY-YYMMDD-NNN`, `paid_at`, `business_date`, actor, optional reference.
- Partial payments are separate records; nothing overwrites `paid_amount`
  without a record behind it (see §6).
- **Overpayment is rejected** (`PAYMENT_EXCEEDS_OUTSTANDING`, 409). No
  deposits / prepayments in A4.
- **Reversal**: a `payment_reversals` row (reason, time, actor), UNIQUE per
  payment → a payment cannot be reversed twice. The payment row is untouched.
  The amount becomes owed again (COMPLETED → CONFIRMED).
- Walk-in sales must stay fully paid, so a single walk-in payment cannot be
  reversed alone (`WALK_IN_MUST_BE_PAID`); reverse the sale instead.

## 5. Who may owe money (credit)

| Buyer | Rule |
| --- | --- |
| anonymous walk-in (`customer_id` null) | must pay in full at confirm (`CREDIT_NOT_ALLOWED`); DB CHECK `sales_walk_in_cash_check` |
| customer with term 0 (cash customer, incl. type WALK_IN) | must pay in full at confirm |
| customer with term N > 0 days | may owe; `due_date = sale business_date + N` |

**Credit limit** (if set on the customer role): `existing outstanding + what
remains owed on this sale ≤ limit`, else `CREDIT_LIMIT_EXCEEDED` (409) with
`available_credit` in the error details. `null` limit = no limit configured.

> Decision change: D6 (A2) said the limit is only a warning. The A4 brief
> (§19) requires a hard rejection; A4 implements the rejection.

Concurrency: before reading the exposure, `ConfirmSale` locks the customer's
CUSTOMER role row (`SELECT … FOR UPDATE`, `DbCustomerCreditGate`). Two credit
sales for one customer therefore run one after the other; the second sees the
first one's outstanding. Cash sales (nothing owed) do not take this lock.
Payments and returns only lower the exposure and do not need it.
There is no database constraint for the limit (it spans many rows); the
lock is the guarantee — proven by a multi-process test and a negative check
(`docs/evidence/a4-negative-checks.txt`).

## 6. Receivable = derived, and the DB keeps the cache honest

- `sales.paid_amount` / `credited_amount` are running sums, updated in the
  same transaction as the payment / return, under the sale row lock.
- `sales.outstanding` is a **generated column**:
  `grand_total − paid − credited` for CONFIRMED/COMPLETED, else 0;
  `CHECK (outstanding >= 0)`.
- A **deferred constraint trigger** (`dgway_check_sale_money`) recomputes
  Σ valid payments, Σ return credits and per-line return counters at COMMIT;
  any disagreement aborts the transaction — even for raw SQL.
- Nobody edits outstanding: there is no endpoint for it.

Queries (`/receivables`, `/receivables/customers`, `/receivables/summary`):
by sale, by customer (outstanding, overdue, due today, oldest due date,
available credit), totals split into *not yet due / due today / overdue*.
They scan only open sales via partial indexes `WHERE outstanding > 0`.

## 7. Customer returns

- Reference the original sale line; quantity ≤ sold − already returned
  (`RETURN_EXCEEDS_SOLD`, 409), checked under the sale lock and by
  `CHECK returned_quantity ≤ quantity`.
- Disposition per line (never automatically sellable):

  | Disposition | Ledger |
  | --- | --- |
  | RETURN_TO_AVAILABLE | CUSTOMER_RETURN +q AVAILABLE |
  | RETURN_TO_QUARANTINE | CUSTOMER_RETURN +q QUARANTINE (recover later via A3 operation) |
  | LOSS (e.g. broken eggs) | CUSTOMER_RETURN +q QUARANTINE → LOSS −q QUARANTINE |

- Value = pro-rata net line price (discount included), rounded once; the
  return that empties a line takes exactly the remainder (Σ returned value =
  line total).
- Money: the value first reduces what is still owed (`credit_amount`); the
  rest was already paid and is refunded (`refund_amount`, `refund_method`
  required). `total_value = credit + refund` (CHECK).
- No reversal of a customer return in A4 (record a new sale instead).

## 8. Sale reversal (compensation)

- Allowed for CONFIRMED / COMPLETED sales without customer returns
  (`SALE_HAS_RETURNS` otherwise → return the rest instead).
- **Payment policy (enforced):** payments are never removed silently. If the
  sale has valid payments, the request must say `reverse_payments=true`;
  then every payment gets a reversal record (refund) in the same
  transaction. Otherwise `SALE_HAS_PAYMENTS` (409).
- Stock goes back through REVERSAL movements linked to the original SALE
  movements (`LedgerReversal`, shared with GR reversal). If the stock is no
  longer there the A3 rule applies (`INSUFFICIENT_STOCK`).
- The original sale stays readable with status REVERSED; a second reversal is
  `INVALID_STATUS_TRANSITION`.

## 9. Cost vs selling price

- Sale lines hold **revenue** data (unit price, discount). Ledger movements of
  sales carry **no cost**. Receipts (A3) hold acquisition cost and yield.
- `/reports/sales-by-product` returns quantity sold, revenue, returns, net,
  average selling price, and — side by side, unprocessed — the period's
  received quantity, purchase cost and average unit cost. No COGS/HPP policy
  is applied.

## 10. Locking order and transaction boundary

Every command is one transaction (document + money + ledger + audit +
idempotency key). Lock order: **sale row → customer credit row → stock
balance rows (sorted)**. Every money change on a sale takes the sale lock,
and the optimistic `version` check is a second line of defence.

## 11. Candidates for the A7 performance test

- `GET /sales` with free-text `q` (ILIKE on number / customer name) on large
  data → may need a trigram index.
- `GET /receivables/customers` GROUP BY over open sales (partial index helps;
  measure with many open sales per customer).
- `reports/sales-by-product` over long periods (joins sale_lines and receipt
  lines; candidate for date-partial indexes or a daily summary table).
- Deferred money trigger cost for sales with many payments / returns.
- Credit lock contention for one very active credit customer.
- `DocumentNumberGenerator` row per prefix/day under high sale throughput.

## A5-B — lots and the credit-limit override

**Lots.** Confirming a sale allocates each line FIFO over the lots of its
position (one SALE movement per line per lot); a return goes back into the
lots the line consumed, in allocation order (docs/BATCH_LOT_MODEL.md §5–6).
Prices, totals, payments and receivables are unchanged by lots.

**Credit limit stays a hard block.** `POST /sales/{id}/confirm` refuses a credit
sale that would exceed the limit (`409 CREDIT_LIMIT_EXCEEDED`). The only way past
it is the dedicated command `POST /sales/{id}/confirm-with-credit-override`
(`ConfirmSaleWithCreditOverride`):

- permission `sales.override_credit_limit` via the `Authorizer` (OWNER today), checked before and again inside the transaction;
- mandatory reason (5–500 characters); no boolean flag on the normal confirm;
- only when the limit IS exceeded (`409 CREDIT_OVERRIDE_NOT_REQUIRED` otherwise); never for cash customers or walk-ins (`CREDIT_NOT_ALLOWED`);
- the customer's `credit_limit` is not modified;
- same customer credit lock as a normal confirmation → concurrent overrides serialize and each sees the other's exposure (`CreditLimitOverrideTest`, OS processes);
- audit `sale.credit_limit_overridden` with `after = { sale_id, sale_number, customer_id, approved_by, reason, credit_limit, exposure_before, requested_credit, exposure_after, overridden_at }`, actor, and `context = { idempotency_key, command, request_id, sale }`.

## F4B — payment method OTHER, lots on the sale detail, correction permissions

**OTHER.** A fourth manual method for settlements that are not cash, transfer or
QRIS (e.g. "Potong deposit customer", "Settlement manual"). It is never
anonymous: the payment's existing `reference` or `notes` must describe it
(`PAYMENT_REFERENCE_REQUIRED`, plus CHECK `pay_other_described_check`). No new
column. A refund with OTHER needs nothing extra — a return's `reason` is already
mandatory. Migration `2026_10_10_100100_f4b_payment_method_other` widens
`pay_method_check` and `cr_refund_method_check`; its `down()` refuses once OTHER
rows exist.

**Which lots supplied a sale.** `GET /sales/{id}` adds, per line, `product` and
`lots[{lot_id, lot_number, consumed, returned}]` from `SaleLotConsumption`
(the ledger; nothing new is stored). Example: lots A 60 KG and B 30 KG, sale
70 KG → A 60, B 10.

**Corrections need a permission.** Reversing a sale (`sales.reverse`), reversing
a payment (`payments.reverse`) and recording a customer return (`sales.return`)
move money or stock after the fact, so they are no longer open to every active
operator. The Authorizer checks them before the transaction (OWNER: `*`).
Routine work — create, confirm, pay, cancel a draft — needs no permission;
the credit-limit override keeps `sales.override_credit_limit`.
