# Deployment (production)

Target: one Linux server (or VM) running Nginx/Caddy + PHP-FPM 8.3 and a
PostgreSQL 16 database (same host or managed). No queue workers, no Redis,
no containers required. Local development: SETUP.md.

## 1. Requirements

- PHP 8.3 with `pdo_pgsql pgsql mbstring intl xml curl zip opcache`; Composer 2.
- PostgreSQL 16 (14+ works; verified on 16). The schema relies on PostgreSQL
  triggers, CHECK and deferred constraint triggers — other databases are not supported.
- HTTPS certificate (Let's Encrypt via Caddy/Certbot). The API must never be served over plain HTTP.
- Cron for the Laravel scheduler (idempotency key pruning).

## 2. Environment (`.env`)

| Variable | Production value | Checked by `dgway:doctor` |
| --- | --- | --- |
| `APP_ENV` | `production` (also enables `DB::prohibitDestructiveCommands`) | FAIL otherwise |
| `APP_DEBUG` | `false` (stack traces never reach clients) | FAIL otherwise |
| `APP_KEY` | `php artisan key:generate` once; keep it secret and stable | FAIL if missing/invalid |
| `APP_URL` | `https://api.<your-domain>` | — |
| `APP_TIMEZONE` / `config/app.php` | `UTC` (instants stored in UTC) | FAIL otherwise |
| `DGWAY_BUSINESS_TIMEZONE` | `Asia/Jakarta` | FAIL if invalid, WARN if not Jakarta |
| `DB_CONNECTION` | `pgsql` | FAIL otherwise |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` | your database | FAIL if empty |
| `DB_PASSWORD` | a generated secret (never `change-me` from `.env.example`) | FAIL if empty/placeholder |
| `DB_SSLMODE` | `require` (or `verify-full`) when the DB is remote | WARN |
| `LOG_LEVEL` | `info` (or `warning`) | WARN on `debug` |
| `DGWAY_TOKEN_TTL_DAYS` | 30 (default) | — |
| `DGWAY_IDEMPOTENCY_RETENTION_DAYS` | 30 (must exceed the longest client retry window) | — |
| `DGWAY_WALK_IN_CUSTOMER_CODE` | `WALK-IN` (default) | — |
| `DGWAY_TRUSTED_PROXIES` | F5A: the reverse proxy's address/network (e.g. `172.16.0.0/12` for a Docker network, or `127.0.0.1`); `*` only when the app port is reachable solely through the proxy | WARN when empty |
| `LOG_STACK` | F5A: `daily` (bare metal, `LOG_DAILY_DAYS=14`) or `stderr` (containers, rotated by Docker) — never `single` | FAIL on `single` |
| `APP_URL` | must start with `https://` | FAIL otherwise |

`.env` is never committed and never shipped in an archive.

## 3. Database roles (recommended)

- **Owner/migration role** (owns the schema): used only to run `php artisan migrate`.
- **Application role** (used by PHP-FPM): `CONNECT`, `USAGE` on schema, `SELECT, INSERT, UPDATE, DELETE` on tables, `USAGE` on sequences — **no** `TRUNCATE`, no ownership.
  Append-only tables refuse UPDATE/DELETE through triggers anyway; withholding
  TRUNCATE closes the one path row triggers cannot see.
  (The current code uses one connection; use the owner role for the deploy
  step only, e.g. `DB_USERNAME=dgway_owner php artisan migrate --force`.)

## 4. First installation

```bash
git clone … /srv/dgway && cd /srv/dgway            # or unpack the release archive
composer install --no-dev --optimize-autoloader
cp .env.example .env && vi .env                     # §2
php artisan key:generate
php artisan migrate --force
printf '%s' "$OWNER_PASSWORD" | php artisan dgway:bootstrap --owner-email=owner@<domain> --owner-name="Owner" --password-stdin
#   second owner (if any):  php artisan dgway:operator:create owner2@<domain> --name="Owner 2"
php artisan config:cache && php artisan route:cache
php artisan dgway:doctor --production               # must end with "Configuration OK."
curl -s https://api.<domain>/api/v1/health/ready    # {"data":{"status":"ready",…}}
```

`dgway:bootstrap` is idempotent: it creates the default node DG-WAY-PRIMARY
(+ site), the WALK-IN cash customer and — only when you pass an email AND a
password through STDIN/prompt — the first OWNER. There is no default account
or password, and re-running never changes an existing password.

Cron (scheduler) — or, in containers, a `php artisan schedule:work` service:
```
* * * * * cd /srv/dgway && php artisan schedule:run >> /dev/null 2>&1
```
Jobs (WIB): 03:15 prune idempotency keys · 03:25 `sanctum:prune-expired --hours=24` · 03:35
`dgway:inventory:verify` (a mismatch is logged at CRITICAL) · every minute a heartbeat file that
`dgway:doctor --production` checks (WARN when older than 5 minutes).

Operators (F5A — there is no operator API; everything is audited):
```
printf '%s' "$PW" | php artisan dgway:operator:create kasir@<domain> --name="Kasir" --role=STAFF --password-stdin
php artisan dgway:operator:list
php artisan dgway:operator:deactivate kasir@<domain>            # revokes every device token at once
php artisan dgway:operator:deactivate kasir@<domain> --activate
printf '%s' "$PW" | php artisan dgway:operator:reset-password kasir@<domain> --password-stdin
```
STAFF does routine work (sales, payments, purchasing, receiving, consumption, cash customers,
products, suppliers) and holds none of the sensitive permissions (reversals, returns, stock
adjustment/correction, credit terms, PO close/cancel, UOM conversions, network and print settings).
Operators change their own password in the app (`POST /auth/password`; other phones are signed out).

Nginx: root `/srv/dgway/public`, `try_files $uri /index.php?$query_string;`,
PHP-FPM socket, `client_max_body_size 2m`, TLS only, HSTS.

## 5. Release procedure (every update)

```bash
cd /srv/dgway
pg_dump … -Fc -f /backups/pre-release-$(date -u +%Y%m%dT%H%M%SZ).dump      # BACKUP_RESTORE.md §1
php artisan dgway:data:fingerprint --output=/backups/pre-release.fingerprint.json
php artisan down                                    # mobile clients get 503 and retry later with the same keys
git pull / unpack release; composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan dgway:data:fingerprint --compare=/backups/pre-release.fingerprint.json --allow-additions   # history unchanged
php artisan dgway:inventory:verify
php artisan config:cache && php artisan route:cache
php artisan dgway:doctor --production
php artisan up
curl -s https://api.<domain>/api/v1/health/ready
```

If any check fails: stay in maintenance mode, investigate; if needed restore
the pre-release dump into a new database (BACKUP_RESTORE.md §2) and point the
previous release at it. **Never run `migrate:rollback` in production** —
schema changes are expand-only (DATA_EVOLUTION_STRATEGY.md), and `down()`
methods are development tools.

## 6. Observability

| What | Where |
| --- | --- |
| Liveness | `GET /api/v1/health` (DB up/down, server time, business date) |
| Readiness | `GET /api/v1/health/ready` (DB, pending migrations, APP_KEY, bootstrap, timezone); 503 = do not route traffic |
| Request correlation | every response has `X-Request-Id` (client-supplied safe value or generated UUIDv7) |
| Logs | `storage/logs/laravel.log`; every line of a request carries `request_id`, and after auth `actor_user_id`; commands add `idempotency_key`; one `api.command` line per command with `command`, `status`, `replayed`, `resource_type`, `resource_id` |
| Audit | `audit_logs` (append-only); each row of a request carries `context.request_id`, `context.idempotency_key`, `context.command` |
| Incident trace | `php artisan dgway:trace <SAL-…/PAY-…/GR-…/LOT-…/uuid>` |
| Unexpected errors | client gets `INTERNAL_ERROR` + `details.reference`; the log line `Unhandled API exception` carries the same `reference` and the request_id |
| Data health | `php artisan dgway:inventory:verify` (daily cron recommended), `dgway:data:fingerprint` |

So a support case "my sale failed" goes: X-Request-Id from the app → log lines
(`request_id`) → `api.command` (`resource_id`) → `audit_logs` (`context.request_id`)
→ `dgway:trace <document>`.

## 7. Security checklist

- HTTPS only; `APP_DEBUG=false`; doctor green.
- Operator passwords ≥ 10 characters, created by the owner on the server (STDIN), never e-mailed;
  each person has their own account (OWNER or STAFF), never shared.
- Behind the proxy, set `DGWAY_TRUSTED_PROXIES` so the login limiter and the logs see the real client IP.
- Tokens are per device (`device_name`); deactivating an operator revokes access immediately.
- Backups encrypted and off-site; restore drill quarterly.
- No default credentials exist anywhere in the code base.
