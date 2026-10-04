<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail loudly on lazy loading, silently discarded attributes and
        // missing attributes during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Generation-1 data is never "dropped and recreated": in production the
        // destructive Artisan commands (migrate:fresh/refresh/reset/rollback,
        // db:wipe) refuse to run. Schema evolution goes forward only
        // (docs/DATA_EVOLUTION_STRATEGY.md §3).
        DB::prohibitDestructiveCommands($this->app->isProduction());

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-email:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
