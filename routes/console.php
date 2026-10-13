<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention of idempotency keys (requires the Laravel scheduler cron in production:
// * * * * * php /path/to/artisan schedule:run).
Illuminate\Support\Facades\Schedule::command('dgway:idempotency:prune')->dailyAt('03:15')->timezone('Asia/Jakarta');

// F5A maintenance (production runs `php artisan schedule:work` in its own container):
// - expired device tokens are deleted (they already stop working at expires_at);
Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:25')->timezone('Asia/Jakarta');
// - the stock projection is checked against the ledger every night; a mismatch is logged
//   at CRITICAL level (repair: dgway:inventory:rebuild-balances, see docs/INVENTORY_LEDGER.md);
Illuminate\Support\Facades\Schedule::command('dgway:inventory:verify')->dailyAt('03:35')->timezone('Asia/Jakarta')
    ->onFailure(fn () => Illuminate\Support\Facades\Log::critical('scheduled dgway:inventory:verify reported an inconsistency'));
// - heartbeat: proves the scheduler runs (checked by dgway:doctor --production).
Illuminate\Support\Facades\Schedule::call(function (): void {
    @file_put_contents((string) config('dgway.scheduler_heartbeat_path'), gmdate(DATE_ATOM));
})->everyMinute()->name('dgway:scheduler-heartbeat');
