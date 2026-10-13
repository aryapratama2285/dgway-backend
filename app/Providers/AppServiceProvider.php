<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
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

        // F5A: behind Caddy/Nginx the client IP, scheme and host come from the
        // X-Forwarded-* headers of the TRUSTED proxy only (DGWAY_TRUSTED_PROXIES:
        // comma-separated IPs/CIDRs, e.g. the Docker network 172.16.0.0/12, or '*'
        // when the app is reachable ONLY through the proxy). Empty = trust none,
        // so a client can never spoof its IP into the login limiter or the logs.
        $proxies = self::trustedProxies();
        if ($proxies !== null) {
            TrustProxies::at($proxies);
        }

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login-email:'.mb_strtolower((string) $request->input('email'))),
            ];
        });

        // F5A: password change — guessing the current password from an unlocked phone is slow.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by('password:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    /** @return string|list<string>|null */
    public static function trustedProxies(): string|array|null
    {
        $raw = trim((string) config('dgway.trusted_proxies', ''));
        if ($raw === '') {
            return null;
        }

        return $raw === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($p) => $p !== ''));
    }
}
