<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention of idempotency keys (requires the Laravel scheduler cron in production:
// * * * * * php /path/to/artisan schedule:run).
Illuminate\Support\Facades\Schedule::command('dgway:idempotency:prune')->dailyAt('03:15')->timezone('Asia/Jakarta');
