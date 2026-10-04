<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

final class AuthenticationApiTest extends DatabaseTestCase
{
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create([
            'name' => 'Owner Satu',
            'email' => 'owner1@dgway.test',
            'password' => Hash::make('rahasia-kuat-123'),
        ]);
    }

    public function test_password_is_stored_hashed_never_plaintext(): void
    {
        $stored = DB::table('users')->where('id', $this->owner->id)->value('password');

        $this->assertNotSame('rahasia-kuat-123', $stored);
        $this->assertTrue(Hash::check('rahasia-kuat-123', $stored));
        $this->assertStringStartsWith('$2y$', $stored, 'bcrypt hash');
    }

    public function test_login_returns_bearer_token_and_operator_without_secrets(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email' => 'OWNER1@dgway.test',
            'password' => 'rahasia-kuat-123',
            'device_name' => 'Android Owner 1',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.operator.email', 'owner1@dgway.test')
            ->assertJsonPath('data.operator.role', 'OWNER')
            ->assertJsonMissingPath('data.operator.password');

        $this->assertMatchesRegularExpression('/^\d+\|\S{40,}$/', $res->json('data.token'));
        $this->assertNotNull($res->json('data.expires_at'));

        $stored = DB::table('personal_access_tokens')->first();
        $this->assertNotSame(explode('|', $res->json('data.token'))[1], $stored->token, 'only a hash of the token is stored');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_user_id' => $this->owner->id]);
        $this->assertNotNull(DB::table('users')->where('id', $this->owner->id)->value('last_login_at'));
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'owner1@dgway.test', 'password' => 'salah', 'device_name' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'actor_user_id' => null]);
    }

    public function test_unknown_email_gets_same_error_as_wrong_password(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@dgway.test', 'password' => 'x', 'device_name' => 'x'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_inactive_operator_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'off@dgway.test', 'password' => Hash::make('rahasia-kuat-123')]);

        $this->postJson('/api/v1/auth/login', ['email' => 'off@dgway.test', 'password' => 'rahasia-kuat-123', 'device_name' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'OPERATOR_INACTIVE');
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password', 'device_name']]]]);
    }

    public function test_protected_endpoint_requires_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer 1|invalid'])->assertStatus(401);
    }

    public function test_me_and_logout_revokes_token(): void
    {
        $token = $this->login();

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('data.id', $this->owner->id);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertNoContent();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_user_id' => $this->owner->id]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_deactivated_operator_token_stops_working_immediately(): void
    {
        $token = $this->login();
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403)->assertJsonPath('error.code', 'OPERATOR_INACTIVE');
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->login();
        DB::table('personal_access_tokens')->update(['expires_at' => now('UTC')->subMinute()]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'owner1@dgway.test', 'password' => 'salah', 'device_name' => 'x'])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'owner1@dgway.test', 'password' => 'salah', 'device_name' => 'x'])
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_health_endpoint_reports_database_and_business_date(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'up')
            ->assertJsonPath('data.business_date', '2026-10-03')
            ->assertJsonPath('data.business_timezone', 'Asia/Jakarta');
    }

    public function test_unknown_api_route_returns_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    private function login(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'owner1@dgway.test',
            'password' => 'rahasia-kuat-123',
            'device_name' => 'Android',
        ])->assertOk()->json('data.token');
    }
}
