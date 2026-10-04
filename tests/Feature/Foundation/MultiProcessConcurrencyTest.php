<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Catalog\CreateCategory;
use App\Application\Catalog\CreateProduct;
use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Application\Shared\Idempotency\IdempotentCommandExecutor;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Exception\DuplicateCode;
use App\Domain\Shared\Identity\Actor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;
use Tests\Support\WorkerProcess;

/**
 * Real concurrency, two OS processes, one PostgreSQL database.
 *
 * Each test starts a worker process ("phone A") that takes a lock/key inside
 * an open transaction, then this process ("phone B") does the competing
 * operation and must wait for A, then behave correctly once A commits.
 */
final class MultiProcessConcurrencyTest extends DatabaseTestCase
{
    private User $user;
    private Actor $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actor = Actor::operator($this->user->id, $this->user->name);
    }

    public function test_second_request_with_same_key_waits_then_replays_first_result(): void
    {
        $key = 'c0ffee00-1111-4222-8333-444455556666';
        $holdMs = 1200;

        // A: acquires the key and holds it (transaction open) for 1.2 s.
        $a = new WorkerProcess('hold-idempotent', [$this->user->id, $key, (string) $holdMs]);
        $a->waitFor('ACQUIRED');

        // B: same key, same payload. Must block until A commits, then replay.
        $started = microtime(true);
        $bRan = false;
        $outcome = $this->app->make(IdempotentCommandExecutor::class)->execute(
            $this->actor, IdempotencyKey::fromString($key), 'test.concurrent', ['qty' => '35'],
            function () use (&$bRan): CommandResult {
                $bRan = true;
                $this->app->make(AuditRecorder::class)->record(new AuditEntry($this->actor, 'test.side_effect', 'test', after: ['by' => 'main']));

                return new CommandResult(201, ['marker' => 'main']);
            },
        );
        $waitedMs = (microtime(true) - $started) * 1000;

        $this->assertSame('DONE replayed=false', $a->waitFor('DONE'));
        $this->assertSame(0, $a->finish());

        $this->assertGreaterThan(500, $waitedMs, 'B must have waited for A to commit');
        $this->assertTrue($outcome->replayed, 'B gets A\'s committed result');
        $this->assertFalse($bRan, 'B\'s command body never executed');
        $this->assertSame(['marker' => 'worker'], $outcome->result->body);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'test.side_effect')->count(), 'side effect happened exactly once');
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_concurrent_creation_of_same_sku_yields_one_product_and_one_clean_duplicate_error(): void
    {
        $category = $this->app->make(CreateCategory::class)->execute($this->actor, 'EGG', 'Telur');

        // A: inserts SKU TLR-A and holds the transaction open.
        $a = new WorkerProcess('hold-create-product', [$this->user->id, $category->id()->value, 'TLR-A', '1000']);
        $a->waitFor('ACQUIRED');

        // B: same SKU at the same time. The unique index makes B wait for A,
        // then fail cleanly — no check-then-insert race, no second row.
        $started = microtime(true);
        try {
            $this->app->make(CreateProduct::class)->execute($this->actor, 'tlr-a', 'Telur Grade A', $category->id()->value, 'KG');
            $this->fail('Second concurrent create must not succeed');
        } catch (DuplicateCode $e) {
            $this->assertSame('DUPLICATE_CODE', $e->errorCode());
        }
        $waitedMs = (microtime(true) - $started) * 1000;

        $a->waitFor('DONE');
        $a->finish();

        $this->assertGreaterThan(400, $waitedMs, 'B waited on the unique index held by A');
        $this->assertSame(1, DB::table('products')->where('sku', 'TLR-A')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'product.created')->count());
    }

    public function test_real_postgresql_deadlock_is_retried_and_the_unit_of_work_completes(): void
    {
        DB::table('document_sequences')->insert([
            ['prefix' => 'LOCKA', 'sequence_date' => '2026-10-03', 'last_value' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['prefix' => 'LOCKB', 'sequence_date' => '2026-10-03', 'last_value' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Partner holds B; it will request A shortly after we hold A.
        $partner = new WorkerProcess('deadlock-partner', ['LOCKA', 'LOCKB', '300']);
        $partner->waitFor('ACQUIRED');

        $attempts = 0;
        $this->app->make(TransactionManager::class)->run(function () use (&$attempts, $partner): void {
            $attempts++;
            DB::selectOne("SELECT 1 FROM document_sequences WHERE prefix = 'LOCKA' FOR UPDATE");
            if ($attempts === 1) {
                $partner->send('go');   // partner will request A in 300 ms
            }
            // We wait for B first (t0); partner waits for A at t0+300ms. Our
            // deadlock_timeout (1 s) fires first, so PostgreSQL picks US as the
            // deadlock victim → TransactionManager must roll back and retry.
            DB::selectOne("SELECT 1 FROM document_sequences WHERE prefix = 'LOCKB' FOR UPDATE");
            $this->app->make(AuditRecorder::class)->record(new AuditEntry($this->actor, 'test.after_deadlock', 'test'));
        });

        $partner->waitFor('DONE');
        $partner->finish();

        $this->assertSame(2, $attempts, 'first attempt was the deadlock victim, second succeeded');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'test.after_deadlock')->count(), 'committed exactly once');
    }
}
