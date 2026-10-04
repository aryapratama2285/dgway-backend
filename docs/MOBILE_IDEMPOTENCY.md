# Mobile idempotency — client rules for the Flutter app

Server design and storage: docs/IDEMPOTENCY.md. This page is what the app
must do so that a bad connection never creates a duplicate sale, payment,
receipt or stock movement.

## 1. The rule

> **One user action = one Idempotency-Key.** Generate it when the user
> confirms the action (taps *Simpan / Bayar / Posting*), store it with the
> request body, and reuse BOTH for every retry of that action until a final
> answer arrives.

- Key format: UUID v4 or v7 (`IDEMPOTENCY_KEY_INVALID` otherwise).
- Header: `Idempotency-Key: <uuid>` on every POST/PATCH command (`428` if missing).
- Keys are scoped per operator: two operators may use the same value safely.
- The server keeps completed keys for `retention_days` (`GET /config`, default 30).
  A retry later than that executes again — never queue a command for longer.

## 2. Algorithm

```
on user confirms action A:
    key  = uuid()
    body = build request JSON
    outbox.save(A, key, body, status=PENDING)        # survives app kill
    send(A)

send(A):
    response = POST url, body, headers {Idempotency-Key: key, X-Request-Id: uuid()}
    case response:
      2xx                         → outbox.done(A, response); show result
                                    (header Idempotent-Replayed: true = it had already succeeded)
      network error / timeout     → keep PENDING, retry later with SAME key + SAME body
      5xx (INTERNAL_ERROR)        → nothing was saved; retry later with SAME key + SAME body
      429                         → back off, retry with SAME key
      401                         → re-login, then retry with SAME key
      409 IDEMPOTENCY_KEY_REUSED  → bug: the body changed under the same key. Do NOT retry; report
      other 4xx (409/422/404/403) → business answer; outbox.failed(A, error.code); show message.
                                    Retrying the same request will give the same answer.
                                    If the user changes something, that is a NEW action → NEW key.
```

"Same body" means the same JSON values. Key order and whitespace do not
matter (the server canonicalises the body before hashing), but any changed
value (quantity, price, a payment line, `expected_version`) makes it a
different request → `409 IDEMPOTENCY_KEY_REUSED`.

## 3. Examples

| Situation | What the app does | Result |
| --- | --- | --- |
| Tap *Bayar 775.000*, connection drops before the response | retry with the same key | payment recorded once; retry gets the original 201 + `Idempotent-Replayed: true` |
| App killed after sending a sale | on restart, outbox resends PENDING with the same key | one sale, one SALE movement per lot, one payment |
| Double tap on *Posting GR* | both taps share the action's key (disable the button; the key also protects) | one posting, one lot per line |
| Two phones post the same GR (different keys) | — | one succeeds, the other gets `409 INVALID_STATUS_TRANSITION`; no second lot |
| Sale failed with `409 INSUFFICIENT_STOCK`, user lowers the quantity | NEW key (new action) | new request evaluated normally |
| Retry after editing the amount but keeping the key | — | `409 IDEMPOTENCY_KEY_REUSED` (app bug) |

## 4. What the server guarantees (tested)

- Replay returns the stored response byte-for-byte; nothing executes again
  (no extra audit row, movement, lot, payment or document number):
  `IdempotencyTest`, `LotLifecycleTest` (GR post / sale / return retries),
  `CreditLimitOverrideTest` (override retry), `MobileApiContractTest`.
- Two requests with the same key at the same time: the second waits for the
  first and then replays its result (`MultiProcessConcurrencyTest`).
- A failed request stores no key, so its retry executes normally.
- Every audit row written by a command carries `context.idempotency_key`,
  `context.command` and `context.request_id`, and the server log line
  `api.command` carries the same ids plus the resulting document id
  (docs/DEPLOYMENT.md §Observability).

## 5. Reads

GET requests need no key and are always safe to repeat. After a command whose
outcome is unknown and cannot be retried (e.g. the outbox entry was lost),
look the document up (`GET /sales?q=…`, `GET /search?q=`) instead of re-sending
with a new key.
