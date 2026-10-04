# Idempotency — design and lifecycle

## Contract

Every mutating business endpoint requires `Idempotency-Key: <uuid>`.
The client generates one key per user action and reuses it for every retry
of that action.

| Situation | Result |
| --- | --- |
| first request | executes, stores response, `Idempotent-Replayed: false` |
| retry, same key + same body | original response, byte-identical, `Idempotent-Replayed: true`, nothing executed |
| same key, different body | `409 IDEMPOTENCY_KEY_REUSED` |
| request failed / rolled back | no key stored → retry executes normally |
| two requests with the same key at the same time | the second **waits** on the row lock, then replays the first's committed result (proven by `MultiProcessConcurrencyTest`) |
| missing / malformed key | `428 IDEMPOTENCY_KEY_REQUIRED` / `400 IDEMPOTENCY_KEY_INVALID` |

Keys are scoped per operator: two operators may use the same key value.

## Storage

`idempotency_keys` row is written in the **same transaction** as the
business change. `response_body` is `json` (verbatim text), not `jsonb`,
so replays are byte-identical.

## Retention

- Keys only need to outlive the client's retry window (minutes to hours).
- Default retention: **30 days** (`DGWAY_IDEMPOTENCY_RETENTION_DAYS`).
- `php artisan dgway:idempotency:prune [--days=N] [--dry-run]` deletes
  COMPLETED keys older than the retention.
- Scheduled daily at 03:15 WIB (`routes/console.php`); production needs the
  standard Laravel scheduler cron: `* * * * * php artisan schedule:run`.
- Nothing else depends on this table: business records are permanent, and
  the audit log keeps the idempotency key in its `context` for traceability.
- Expected volume: ~100–300 commands/day for 2 operators → under 10 000 rows
  at 30-day retention; no partitioning needed.
