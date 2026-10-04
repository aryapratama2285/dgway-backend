<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shared\Time\Clock;
use App\Http\Controllers\Controller;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Liveness + database reachability + server time. The Flutter app uses it to
 * tell "no internet" apart from "server down" before submitting transactions.
 */
final class HealthController extends Controller
{
    public function __invoke(ConnectionInterface $db, Clock $clock): JsonResponse
    {
        try {
            $db->selectOne('SELECT 1 AS ok');
            $database = 'up';
        } catch (Throwable) {
            $database = 'down';
        }

        return new JsonResponse([
            'data' => [
                'status' => $database === 'up' ? 'ok' : 'degraded',
                'database' => $database,
                'server_time_utc' => $clock->now()->format(DATE_ATOM),
                'business_date' => $clock->today()->toString(),
                'business_timezone' => config('dgway.business_timezone'),
                'api_version' => 'v1',
            ],
        ], $database === 'up' ? 200 : 503);
    }

    /**
     * Readiness (A5-B §21): may this instance take traffic? Checks the
     * database, pending migrations, APP_KEY, the bootstrap (default node) and
     * the business timezone. Reports only statuses — never error messages,
     * hosts, credentials or versions — and 503 when anything is not ready.
     */
    public function ready(ConnectionInterface $db, Migrator $migrator): JsonResponse
    {
        $checks = ['database' => 'fail', 'migrations' => 'unknown', 'app_key' => 'missing', 'default_node' => 'unknown', 'timezone' => 'invalid'];
        try {
            $db->selectOne('SELECT 1 AS ok');
            $checks['database'] = 'ok';
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_diff($files, $migrator->getRepository()->getRan());
            $checks['migrations'] = $pending === [] ? 'ok' : 'pending';
            $checks['default_node'] = $db->table('nodes')->where('code', (string) config('dgway.default_node_code'))->where('is_active', true)->exists() ? 'ok' : 'missing';
        } catch (Throwable) {
            // details stay in the server; the probe only learns "not ready"
        }
        $checks['app_key'] = (string) config('app.key') !== '' ? 'ok' : 'missing';
        $checks['timezone'] = in_array((string) config('dgway.business_timezone'), DateTimeZone::listIdentifiers(), true) ? 'ok' : 'invalid';
        $ready = ! in_array(false, array_map(fn ($v) => $v === 'ok', $checks), true);

        return new JsonResponse(['data' => ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks]], $ready ? 200 : 503);
    }
}
