# Backup and restore (PostgreSQL)

DG Way keeps **all** business state in one PostgreSQL 16 database: master data,
documents, the append-only ledger, lots, money, audit and idempotency keys.
There are no files to back up besides `.env` (secrets — keep it in your secret
store, never in the backup bucket next to the dump).

The procedure below was executed end-to-end on a copy of the A5-B smoke
database (`docs/evidence/a5b-backup-restore-drill.txt`): dump → restore into a
new database → migrations "nothing to migrate" → fingerprint identical →
inventory verify consistent → append-only guards still active → doctor OK.

## 1. Backup

```bash
# logical, consistent snapshot (pg_dump uses one REPEATABLE READ transaction; the app may keep running)
export PGPASSWORD=…                     # or ~/.pgpass
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
pg_dump -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE -Fc --no-owner --no-privileges -f dgway-$STAMP.dump

# record the business fingerprint of the SAME moment (taken right after the dump)
php artisan dgway:data:fingerprint --output=dgway-$STAMP.fingerprint.json
sha256sum dgway-$STAMP.dump > dgway-$STAMP.dump.sha256
```

- Schedule: at least daily, plus before every deployment/migration.
- Keep: 7 daily + 4 weekly + 12 monthly (adjust to your storage). Copy off the server (other region/provider), encrypted.
- The fingerprint is taken after the dump; if transactions happened in between,
  compare on a quiet moment or accept that `audit`/sequence counters differ — the
  economic invariants (stock, receivables, payments) are what you check.
- For point-in-time recovery (RPO minutes instead of a day) enable WAL archiving
  / managed PITR on the database host; out of scope for the app.

## 2. Restore into a NEW environment

```bash
createdb -h $DB_HOST -U $DB_USERNAME dgway_restore
sha256sum -c dgway-$STAMP.dump.sha256
pg_restore -h $DB_HOST -U $DB_USERNAME -d dgway_restore --no-owner --no-privileges --exit-on-error dgway-$STAMP.dump
```

Then point the app at it (`DB_DATABASE=dgway_restore` in `.env`) and run:

```bash
php artisan migrate --force                     # applies only migrations newer than the backup ("Nothing to migrate" if same version)
php artisan dgway:data:fingerprint --compare=dgway-$STAMP.fingerprint.json   # must say: Reconciliation OK
#   if migrations ran (restoring an older backup into a newer release):
php artisan dgway:data:fingerprint --compare=dgway-$STAMP.fingerprint.json --allow-additions
php artisan dgway:inventory:verify              # ledger vs stock_balances AND stock_lot_balances
php artisan dgway:doctor --production           # configuration sanity (exit 1 = do not open to users)
curl -s https://<host>/api/v1/health/ready      # {"status":"ready", …}
```

Smoke checks after the restore (read-only, as the owner): log in, `GET /config`,
`GET /dashboard`, `GET /receivables/summary`, `GET /lots?status=active`,
`php artisan dgway:trace <a recent SAL-… number>`. Compare totals with the
last report you trust.

Notes:
- Triggers (append-only guards, lot/ledger guards) are restored **after** the
  data (pg_dump post-data section), so the restore itself is never blocked;
  CHECK constraints are validated during the load.
- Never restore with `--data-only` into a schema created by `migrate`: run the
  full restore into an empty database.
- `dgway:inventory:rebuild-balances --force` is safe after a restore if verify
  ever reports projection drift (projections are rebuildable; the ledger is not).

## 3. What must NOT be done

- No `migrate:fresh`, `db:wipe`, `migrate:rollback` in production
  (`DB::prohibitDestructiveCommands` blocks them when APP_ENV=production).
- No manual UPDATE/DELETE on ledger, documents, payments, lots, audit: the
  database refuses (append-only triggers); corrections are business documents
  (reversal, return, adjustment).
- The production DB role should not own the tables and should not hold the
  TRUNCATE privilege (TRUNCATE bypasses row triggers) — see DEPLOYMENT.md §3.

## 4. Restore drill checklist (quarterly)

1. Restore yesterday's dump into a scratch database.
2. Fingerprint compare + inventory verify + doctor: all green.
3. Open the app against it (staging) and run the smoke checks.
4. Record the date, dump size, duration and result.
