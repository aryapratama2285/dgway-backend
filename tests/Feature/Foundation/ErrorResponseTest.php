<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Production 500 responses must not leak internals: no stack trace, no SQL,
 * no credentials, no file paths, no exception class names.
 */
final class ErrorResponseTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::get('api/v1/_test/boom', fn () => throw new RuntimeException(
            'Connection failed for user dgway password=dgway_local at '.base_path('app/Secret.php').' running SELECT * FROM users'
        ))->middleware('api');

        Route::get('api/v1/_test/bad-sql', fn () => DB::select('SELECT * FROM table_that_does_not_exist WHERE password = ?', ['s3cret']))
            ->middleware('api');
    }

    public function test_unexpected_exception_returns_generic_500_with_reference(): void
    {
        $res = $this->getJson('api/v1/_test/boom');

        $res->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['reference']]])
            ->assertJsonMissingPath('error.details.debug');

        $this->assertNoLeak($res->getContent());
    }

    public function test_database_error_does_not_leak_sql_or_parameters(): void
    {
        $res = $this->getJson('api/v1/_test/bad-sql');

        $res->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertNoLeak($res->getContent());
        $this->assertStringNotContainsString('table_that_does_not_exist', $res->getContent());
        $this->assertStringNotContainsString('s3cret', $res->getContent());
    }

    public function test_debug_mode_adds_details_only_when_explicitly_enabled(): void
    {
        config(['app.debug' => true]);

        $this->getJson('api/v1/_test/boom')->assertStatus(500)->assertJsonPath('error.details.debug.exception', RuntimeException::class);
    }

    private function assertNoLeak(string $body): void
    {
        foreach (['password', 'dgway_local', 'SELECT', 'trace', '#0', base_path(), '.php', 'RuntimeException', 'QueryException', 'SQLSTATE'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body, "500 response leaked '{$needle}'");
        }
    }
}
