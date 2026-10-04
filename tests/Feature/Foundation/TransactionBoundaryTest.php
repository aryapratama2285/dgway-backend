<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * Proves the transaction boundary against real PostgreSQL:
 * a unit of work commits completely or not at all.
 */
final class TransactionBoundaryTest extends DatabaseTestCase
{
    private TransactionManager $tx;
    private AuditRecorder $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tx = $this->app->make(TransactionManager::class);
        $this->audit = $this->app->make(AuditRecorder::class);
    }

    public function test_successful_unit_of_work_commits_all_writes(): void
    {
        $this->tx->run(function (): void {
            $this->write('step.one');
            $this->write('step.two');
        });

        $this->assertSame(2, DB::table('audit_logs')->count());
        $this->assertFalse($this->tx->inTransaction());
    }

    public function test_failure_mid_unit_of_work_rolls_back_every_write(): void
    {
        // Simulates "sale created but stock update failed": nothing may persist.
        try {
            $this->tx->run(function (): void {
                $this->write('sale.created');
                $this->write('sale.items.created');
                throw new RuntimeException('stock movement failed');
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('stock movement failed', $e->getMessage());
        }

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertFalse($this->tx->inTransaction());
    }

    public function test_nested_run_joins_outer_transaction(): void
    {
        $this->tx->run(function (): void {
            $this->write('outer');
            $this->tx->run(function (): void {
                $this->assertTrue($this->tx->inTransaction());
                $this->write('inner');
            });
        });

        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_failure_in_nested_work_rolls_back_the_whole_outer_unit(): void
    {
        try {
            $this->tx->run(function (): void {
                $this->write('outer');
                $this->tx->run(function (): void {
                    $this->write('inner');
                    throw new RuntimeException('inner failed');
                });
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_database_constraint_violation_rolls_back_prior_writes(): void
    {
        try {
            $this->tx->run(function (): void {
                $this->write('before.violation');
                DB::table('uoms')->insert(['id' => '01928a3c-0000-7000-8000-0000000000fe', 'code' => 'KG', 'name' => 'dup', 'precision' => 3, 'created_at' => now(), 'updated_at' => now()]);
            });
            $this->fail('Expected unique violation');
        } catch (\Illuminate\Database\QueryException) {
        }

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    private function write(string $action): void
    {
        $this->audit->record(new AuditEntry(Actor::system('test'), $action, 'test'));
    }
}
