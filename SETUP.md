# Setup (local development)

## Requirements

- PHP **8.3+** with extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `xml`, `curl`, `zip`
- Composer 2
- PostgreSQL **16** (14+ should work; verified on 16.15)

## 1. Database

```bash
sudo -u postgres psql -c "CREATE USER dgway WITH PASSWORD 'change-me' CREATEDB;"
sudo -u postgres createdb -O dgway dgway        # application database
sudo -u postgres createdb -O dgway dgway_test   # used by the test suite
```

The test database credentials live in `phpunit.xml` (`dgway` / `dgway_local`
on `127.0.0.1:5432`). Change them there if your local setup differs.

## 2. Application

```bash
composer install
cp .env.example .env          # set DB_PASSWORD
php artisan key:generate
php artisan migrate
```

## 3. Bootstrap (recommended, A5-B)

```bash
printf '%s' "$OWNER_PASSWORD" | php artisan dgway:bootstrap --owner-email=owner1@example.com --owner-name="Owner 1" --password-stdin
```

Idempotent: checks units, creates node DG-WAY-PRIMARY (+ site), the WALK-IN
cash customer and the owner (only with a password you supply). Steps 3b/4
below remain available individually.

## 3b. Create the operator accounts

No default users or passwords exist. Create each owner from the console
(password is prompted, hidden):

```bash
php artisan dgway:operator:create owner1@example.com --name="Owner 1"
php artisan dgway:operator:create owner2@example.com --name="Owner 2"
```

## 4. Default network node

```bash
php artisan dgway:network:bootstrap   # creates node DG-WAY-PRIMARY (idempotent)
```

## 5. Run

```bash
php artisan serve             # http://localhost:8000
curl http://localhost:8000/api/v1/health
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"owner1@example.com","password":"…","device_name":"test"}'
```

## Production notes (deployment is planned after A1–A4 are stable)

- Serve only over HTTPS (TLS terminated by Nginx/Caddy in front of PHP-FPM).
- `APP_ENV=production`, `APP_DEBUG=false` (errors then never leak internals).
- Automated PostgreSQL backups (daily `pg_dump` off-server, tested restore).
- `php artisan config:cache route:cache` on deploy.
- Scheduler cron (prunes old idempotency keys daily):
  `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
