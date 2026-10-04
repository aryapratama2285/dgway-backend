<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Retry policy of the transaction boundary (complements the real-deadlock
 * test in MultiProcessConcurrencyTest with deterministic error injection).
 */
final class TransactionRetryTest extends DatabaseTestCase
{
    private TransactionManager $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tx = $this->app->make(TransactionManager::class);
    }

    public function test_serialization_failure_40001_is_retried(): void
    {
        $this->assertRetriedOnce($this->pgError('40001', 'could not serialize access due to concurrent update'));
    }

    public function test_deadlock_40P01_is_retried(): void
    {
        $this->assertRetriedOnce($this->pgError('40P01', 'deadlock detected'));
    }

    public function test_non_concurrency_errors_are_not_retried(): void
    {
        $attempts = 0;
        try {
            $this->tx->run(function () use (&$attempts): void {
                $attempts++;
                throw $this->pgError('23505', 'duplicate key value violates unique constraint');
            });
            $this->fail();
        } catch (QueryException) {
        }

        $this->assertSame(1, $attempts);
    }

    public function test_gives_up_after_three_attempts_and_rolls_back(): void
    {
        $attempts = 0;
        try {
            $this->tx->run(function () use (&$attempts): void {
                $attempts++;
                $this->write();
                throw $this->pgError('40P01', 'deadlock detected');
            });
            $this->fail();
        } catch (QueryException $e) {
            $this->assertStringContainsString('deadlock', $e->getMessage());
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(0, DB::table('audit_logs')->count(), 'no partial writes from any attempt');
    }

    public function test_business_exceptions_are_not_retried(): void
    {
        $attempts = 0;
        try {
            $this->tx->run(function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('business rule failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $attempts);
    }

    private function assertRetriedOnce(QueryException $error): void
    {
        $attempts = 0;
        $this->tx->run(function () use (&$attempts, $error): void {
            $attempts++;
            $this->write();
            if ($attempts === 1) {
                throw $error;
            }
        });

        $this->assertSame(2, $attempts);
        $this->assertSame(1, DB::table('audit_logs')->count(), 'first attempt rolled back, second committed');
    }

    private function write(): void
    {
        $this->app->make(AuditRecorder::class)->record(new AuditEntry(Actor::system('test'), 'test.write', 'test'));
    }

    private function pgError(string $sqlState, string $message): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: {$message}");
        $pdo->errorInfo = [$sqlState, 7, $message];

        return new QueryException('pgsql', 'SELECT 1', [], $pdo);
    }
}
