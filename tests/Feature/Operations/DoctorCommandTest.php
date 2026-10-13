<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\Artisan;
use Tests\DatabaseTestCase;

/** A5-B §22 — production config sanity check. */
final class DoctorCommandTest extends DatabaseTestCase
{
    private function run_(array $config = []): array
    {
        config($config);
        $code = Artisan::call('dgway:doctor', ['--production' => true, '--json' => true]);
        $out = Artisan::output();
        $json = json_decode(substr($out, (int) strpos($out, '{'), (int) strrpos($out, '}') - (int) strpos($out, '{') + 1), true);

        return [$code, collect($json['checks'])->keyBy('check')->map(fn ($c) => $c['result'])->all(), $out];
    }

    public function test_development_settings_fail_production_rules_without_printing_secrets(): void
    {
        [$code, $r, $out] = $this->run_(['app.debug' => true, 'database.connections.pgsql.password' => 'change-me', 'app.key' => '']);
        $this->assertSame(1, $code);
        $this->assertSame(['FAIL', 'FAIL', 'FAIL', 'FAIL'], [$r['APP_KEY'], $r['APP_DEBUG'], $r['DB_PASSWORD'], $r['bootstrap: default node']]);
        $this->assertStringNotContainsString('change-me', $out);
    }

    public function test_safe_production_settings_pass(): void
    {
        $this->artisan('dgway:bootstrap');
        \Illuminate\Support\Facades\DB::table('users')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'name' => 'Owner', 'email' => 'o@dgway.test', 'password' => bcrypt('x-very-long-pass'), 'role' => 'OWNER', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $secret = 'S3cure-'.bin2hex(random_bytes(8));
        $this->app['env'] = 'production';
        [$code, $r, $out] = $this->run_(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'database.connections.pgsql.password' => $secret,
            // F5A production rules: HTTPS URL and bounded logs (independent of the local .env)
            'app.url' => 'https://api.dgway.test', 'logging.default' => 'stack', 'logging.channels.stack.channels' => ['daily']]);
        $this->assertSame('OK', $r['APP_KEY']);
        $this->assertSame('OK', $r['DB_PASSWORD']);
        $this->assertSame('OK', $r['migrations']);
        $this->assertSame('OK', $r['DGWAY_BUSINESS_TIMEZONE']);
        $this->assertSame([], array_keys(array_filter($r, fn ($v) => $v === 'FAIL')), $out);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString($secret, $out);
        $this->app['env'] = 'testing';
    }
}
