<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Application\Shared\Idempotency\IdempotencyKeyReused;
use App\Application\Shared\Idempotency\IdempotentCommandExecutor;
use App\Application\Shared\Security\ActorContext;
use App\Domain\Shared\Identity\Actor;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Support\IdempotentResponse;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PDO;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Proves, against real PostgreSQL, that a retried command never creates a
 * duplicate transaction (requirement: "jangan membuat duplicate transaction").
 */
final class IdempotencyTest extends DatabaseTestCase
{
    private IdempotentCommandExecutor $executor;
    private User $user;
    private Actor $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = $this->app->make(IdempotentCommandExecutor::class);
        $this->user = User::factory()->create();
        $this->actor = Actor::operator($this->user->id, $this->user->name);
    }

    public function test_retry_with_same_key_returns_stored_result_without_re_executing(): void
    {
        $key = IdempotencyKey::fromString('0f8b6a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');

        $first = $this->executor->execute($this->actor, $key, 'test.create', ['qty' => '35'], fn () => $this->sideEffect('A'));
        $second = $this->executor->execute($this->actor, $key, 'test.create', ['qty' => '35'], fn () => $this->sideEffect('B'));

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame(['marker' => 'A'], $second->result->body);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'test.side_effect')->count(), 'side effect happened exactly once');
        $this->assertSame('COMPLETED', DB::table('idempotency_keys')->value('status'));
    }

    public function test_same_key_different_payload_is_rejected(): void
    {
        $key = IdempotencyKey::fromString('0f8b6a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');
        $this->executor->execute($this->actor, $key, 'test.create', ['amount' => '700000'], fn () => $this->sideEffect('A'));

        $this->expectException(IdempotencyKeyReused::class);
        $this->executor->execute($this->actor, $key, 'test.create', ['amount' => '900000'], fn () => $this->sideEffect('B'));
    }

    public function test_keys_are_scoped_per_operator(): void
    {
        $other = User::factory()->create();
        $key = IdempotencyKey::fromString('0f8b6a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');

        $a = $this->executor->execute($this->actor, $key, 'test.create', [], fn () => $this->sideEffect('A'));
        $b = $this->executor->execute(Actor::operator($other->id, $other->name), $key, 'test.create', [], fn () => $this->sideEffect('B'));

        $this->assertFalse($a->replayed);
        $this->assertFalse($b->replayed);
        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }

    public function test_failed_attempt_leaves_no_key_and_retry_executes(): void
    {
        $key = IdempotencyKey::fromString('0f8b6a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b');

        try {
            $this->executor->execute($this->actor, $key, 'test.create', [], function () {
                $this->sideEffect('half-done');
                throw new RuntimeException('connection dropped mid-transaction');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        $retry = $this->executor->execute($this->actor, $key, 'test.create', [], fn () => $this->sideEffect('A'));
        $this->assertFalse($retry->replayed);
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_concurrent_request_with_same_key_waits_instead_of_duplicating(): void
    {
        $key = '0f8b6a3e-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
        $hash = IdempotentCommandExecutor::hashRequest('test.create', []);

        // A second, independent database session holds the key, uncommitted
        // (i.e. another phone is in the middle of submitting the same action).
        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->prepare("INSERT INTO idempotency_keys (id, actor_scope, actor_user_id, idempotency_key, command, request_hash, status, created_at)
                         VALUES (gen_random_uuid(), ?, ?, ?, 'test.create', ?, 'PENDING', now())")
            ->execute([$this->user->id, $this->user->id, $key, $hash]);

        DB::statement("SET lock_timeout = '300ms'");
        try {
            $this->executor->execute($this->actor, IdempotencyKey::fromString($key), 'test.create', [], fn () => $this->sideEffect('dup'));
            $this->fail('The second request must block, not execute');
        } catch (QueryException $e) {
            $this->assertStringContainsString('lock timeout', $e->getMessage());
        } finally {
            DB::statement('RESET lock_timeout');
        }
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'test.side_effect')->count());

        // The first request fails and rolls back → the key is free again.
        $other->rollBack();
        $outcome = $this->executor->execute($this->actor, IdempotencyKey::fromString($key), 'test.create', [], fn () => $this->sideEffect('A'));
        $this->assertFalse($outcome->replayed);
    }

    public function test_http_retry_returns_same_response_and_flags_replay(): void
    {
        Route::post('api/v1/_test/commands', function (Request $request, IdempotentCommandExecutor $executor, ActorContext $actors) {
            return IdempotentResponse::from($executor->execute(
                $actors->current(),
                $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE),
                'test.http',
                $request->all(),
                fn () => new CommandResult(201, ['data' => ['id' => (string) \Illuminate\Support\Str::uuid7(), 'qty' => $request->input('qty')]]),
            ));
        })->middleware(['api', 'auth:sanctum', 'idempotency']);

        Sanctum::actingAs($this->user, ['operate']);
        $headers = ['Idempotency-Key' => '7d0c1a2b-3c4d-4e5f-9a8b-7c6d5e4f3a2b'];

        $first = $this->postJson('api/v1/_test/commands', ['qty' => '35'], $headers);
        $second = $this->postJson('api/v1/_test/commands', ['qty' => '35'], $headers);

        $first->assertStatus(201)->assertHeader('Idempotent-Replayed', 'false');
        $second->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'), 'retry must return the original resource, not a new one');
        $this->assertSame($first->getContent(), $second->getContent(), 'replay is byte-for-byte identical');

        $this->postJson('api/v1/_test/commands', ['qty' => '40'], $headers)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->postJson('api/v1/_test/commands', ['qty' => '35'])
            ->assertStatus(428)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson('api/v1/_test/commands', ['qty' => '35'], ['Idempotency-Key' => 'x'])
            ->assertStatus(400)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_INVALID');
    }

    private function sideEffect(string $marker): CommandResult
    {
        $this->app->make(AuditRecorder::class)->record(new AuditEntry($this->actor, 'test.side_effect', 'test', after: ['marker' => $marker]));

        return new CommandResult(201, ['marker' => $marker]);
    }

    private function secondConnection(): PDO
    {
        $c = config('database.connections.pgsql');

        return new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
