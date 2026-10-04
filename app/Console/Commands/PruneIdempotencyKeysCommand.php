<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Retention for idempotency_keys (see docs/IDEMPOTENCY.md).
 *
 * A key only needs to live as long as a client might retry the same action.
 * The Flutter app retries within minutes; 30 days is a wide safety margin.
 * Business records never depend on this table (audit_logs keeps the key in
 * its context for traceability), so pruning old keys loses nothing.
 */
final class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'dgway:idempotency:prune {--days= : Keep keys newer than this many days (default from config)} {--dry-run}';

    protected $description = 'Delete committed idempotency keys older than the retention period';

    public function handle(ConnectionInterface $db): int
    {
        $days = (int) ($this->option('days') ?: config('dgway.idempotency_retention_days', 30));
        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $query = $db->table('idempotency_keys')
            ->where('status', 'COMPLETED')
            ->where('created_at', '<', $db->raw("now() - make_interval(days => {$days})"));

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} idempotency key(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} idempotency key(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
