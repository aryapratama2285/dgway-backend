<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Production configuration sanity check (A5-B §22). Read-only.
 *
 *   php artisan dgway:doctor               (rules for the current APP_ENV)
 *   php artisan dgway:doctor --production  (apply production rules anywhere, e.g. in CI before deploy)
 *
 * Exit code 1 when any check FAILS (deploy should stop); WARN does not fail.
 * Never prints secret values — only whether they are acceptable.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'dgway:doctor {--production : Apply production rules regardless of APP_ENV} {--json : machine-readable output}';

    protected $description = 'Check that configuration is safe for production (APP_KEY, debug, URL, proxies, logs, scheduler, DB, timezone, migrations, bootstrap)';

    /** Values copied from .env.example that must never reach production. */
    private const PLACEHOLDER_SECRETS = ['', 'change-me', 'secret', 'password', 'dgway'];

    public function handle(ConnectionInterface $db, Migrator $migrator): int
    {
        $prod = $this->option('production') || app()->isProduction();
        $checks = [];
        $add = function (string $check, bool $ok, string $detail, bool $warnOnly = false) use (&$checks): void {
            $checks[] = ['check' => $check, 'result' => $ok ? 'OK' : ($warnOnly ? 'WARN' : 'FAIL'), 'detail' => $detail];
        };

        $key = (string) config('app.key');
        $add('APP_KEY', $key !== '' && (str_starts_with($key, 'base64:') ? strlen((string) base64_decode(substr($key, 7), true)) === 32 : strlen($key) === 32),
            $key === '' ? 'missing: php artisan key:generate' : 'set (value not shown)');
        $add('APP_ENV', ! $prod || app()->environment('production'), 'APP_ENV='.app()->environment().' (production also blocks destructive DB commands: DB::prohibitDestructiveCommands)');
        $add('APP_DEBUG', ! $prod || config('app.debug') === false, config('app.debug') ? 'true (stack traces would reach clients)' : 'false');
        $add('LOG_LEVEL', ! $prod || ! in_array(config('logging.channels.'.config('logging.default').'.level', config('logging.channels.single.level')), ['debug'], true),
            'level='.(string) (config('logging.channels.single.level') ?? 'n/a'), true);
        $add('app.timezone', config('app.timezone') === 'UTC', 'app.timezone='.config('app.timezone').' (instants are stored in UTC)');
        $tz = (string) config('dgway.business_timezone');
        $add('DGWAY_BUSINESS_TIMEZONE', in_array($tz, DateTimeZone::listIdentifiers(), true), $tz);
        $add('business timezone is Asia/Jakarta', $tz === 'Asia/Jakarta', $tz, true);

        $conn = (array) config('database.connections.'.config('database.default'));
        $add('DB_CONNECTION', ($conn['driver'] ?? null) === 'pgsql', 'driver='.($conn['driver'] ?? 'none').' (PostgreSQL required: triggers, CHECKs, deferred constraints)');
        foreach (['host' => 'DB_HOST', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME'] as $k => $env) {
            $add($env, (string) ($conn[$k] ?? '') !== '', (string) ($conn[$k] ?? '') !== '' ? 'set' : 'missing');
        }
        $add('DB_PASSWORD', ! $prod || ! in_array((string) ($conn['password'] ?? ''), self::PLACEHOLDER_SECRETS, true),
            in_array((string) ($conn['password'] ?? ''), self::PLACEHOLDER_SECRETS, true) ? 'empty or a placeholder value' : 'set (value not shown)');
        $add('DB_SSLMODE', ! $prod || in_array($conn['sslmode'] ?? 'prefer', ['require', 'verify-ca', 'verify-full'], true) || in_array($conn['host'] ?? '', ['127.0.0.1', 'localhost', '/var/run/postgresql'], true),
            'sslmode='.($conn['sslmode'] ?? 'default').' host='.(in_array($conn['host'] ?? '', ['127.0.0.1', 'localhost'], true) ? 'local' : 'remote'), true);

        // F5A — production edge: HTTPS URL, proxy trust, bounded logs, scheduler running.
        $url = (string) config('app.url');
        $add('APP_URL', ! $prod || str_starts_with($url, 'https://'), $url === '' ? 'missing' : (str_starts_with($url, 'https://') ? 'https' : 'not https (the API must only be served over TLS)'));
        $proxies = \App\Providers\AppServiceProvider::trustedProxies();
        $add('DGWAY_TRUSTED_PROXIES', ! $prod || $proxies !== null,
            $proxies === null ? 'none trusted (behind Caddy/Nginx every client gets the proxy IP: one shared login limit, no client IP in logs)' : (is_array($proxies) ? implode(',', $proxies) : $proxies), true);
        $channel = (string) config('logging.default');
        $channels = $channel === 'stack' ? (array) config('logging.channels.stack.channels', []) : [$channel];
        $add('log channel', ! $prod || ! in_array('single', $channels, true), 'channels='.implode(',', $channels).(in_array('single', $channels, true) ? ' (single file grows without limit: use daily or stderr)' : ''));
        $beat = (string) config('dgway.scheduler_heartbeat_path');
        $age = is_file($beat) ? time() - (int) filemtime($beat) : null;
        $add('scheduler heartbeat', ! $prod || ($age !== null && $age <= 300),
            $age === null ? 'never written (run: php artisan schedule:work, or cron schedule:run)' : "last beat {$age}s ago", true);

        try {
            $db->selectOne('SELECT 1');
            $add('database reachable', true, 'ok');
            $pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
            $add('migrations', $pending === [], $pending === [] ? 'all applied' : count($pending).' pending: php artisan migrate --force');
            $add('bootstrap: default node', $db->table('nodes')->where('code', (string) config('dgway.default_node_code'))->exists(), (string) config('dgway.default_node_code'));
            $add('bootstrap: walk-in customer', $db->table('business_partners')->where('code', (string) config('dgway.walk_in_customer_code'))->exists(), (string) config('dgway.walk_in_customer_code'), true);
            $add('bootstrap: operator', $db->table('users')->where('is_active', true)->where('role', 'OWNER')->exists(), 'at least one active OWNER (dgway:bootstrap --owner-email=… --password-stdin)');
        } catch (Throwable) {
            $add('database reachable', false, 'connection failed (details in the server log, not printed here)');
        }

        if ($this->option('json')) {
            $this->line(json_encode(['production_rules' => $prod, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Rules: '.($prod ? 'production' : app()->environment()));
            $this->table(['check', 'result', 'detail'], $checks);
        }
        $failed = array_filter($checks, fn ($c) => $c['result'] === 'FAIL');
        if ($failed !== []) {
            $this->error(count($failed).' check(s) failed.');

            return self::FAILURE;
        }
        $this->info('Configuration OK.');

        return self::SUCCESS;
    }
}
