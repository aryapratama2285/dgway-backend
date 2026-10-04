<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotencyClaim;
use App\Application\Shared\Idempotency\IdempotencyKey;
use App\Application\Shared\Idempotency\IdempotencyKeyReused;
use App\Application\Shared\Idempotency\IdempotencyStore;
use App\Application\Shared\Idempotency\IdempotentCommandExecutor;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Shared\Identity\Actor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure unit test of the idempotency algorithm with in-memory fakes.
 * The PostgreSQL-backed behaviour is covered in tests/Feature/Foundation.
 */
final class IdempotentCommandExecutorTest extends TestCase
{
    private InMemoryTransactions $tx;
    private InMemoryIdempotencyStore $store;
    private IdempotentCommandExecutor $executor;
    private Actor $actor;

    protected function setUp(): void
    {
        $this->tx = new InMemoryTransactions();
        $this->store = new InMemoryIdempotencyStore($this->tx);
        $this->executor = new IdempotentCommandExecutor($this->tx, $this->store);
        $this->actor = Actor::operator('0192f0a0-1234-7abc-8def-0123456789ab', 'Owner');
    }

    public function test_same_key_and_payload_executes_once_and_replays_result(): void
    {
        $key = IdempotencyKey::fromString('9b2f6a0e-5f0b-4c3a-9a51-0c1d2e3f4a5b');
        $runs = 0;
        $command = function () use (&$runs) {
            $runs++;

            return new CommandResult(201, ['sale_number' => 'SL-261003-001']);
        };

        $first = $this->executor->execute($this->actor, $key, 'sales.create', ['qty' => '35'], $command);
        $second = $this->executor->execute($this->actor, $key, 'sales.create', ['qty' => '35'], $command);

        $this->assertSame(1, $runs);
        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame($first->result->body, $second->result->body);
        $this->assertSame(201, $second->result->statusCode);
    }

    public function test_payload_key_order_does_not_matter(): void
    {
        $this->assertSame(
            IdempotentCommandExecutor::hashRequest('x', ['a' => 1, 'b' => ['d' => 2, 'c' => 3]]),
            IdempotentCommandExecutor::hashRequest('x', ['b' => ['c' => 3, 'd' => 2], 'a' => 1]),
        );
    }

    public function test_reusing_key_with_different_payload_is_rejected(): void
    {
        $key = IdempotencyKey::fromString('9b2f6a0e-5f0b-4c3a-9a51-0c1d2e3f4a5b');
        $this->executor->execute($this->actor, $key, 'payments.record', ['amount' => '700000'], fn () => new CommandResult(201, ['ok' => true]));

        $this->expectException(IdempotencyKeyReused::class);
        $this->executor->execute($this->actor, $key, 'payments.record', ['amount' => '900000'], fn () => new CommandResult(201, ['ok' => true]));
    }

    public function test_failed_command_does_not_consume_the_key(): void
    {
        $key = IdempotencyKey::fromString('9b2f6a0e-5f0b-4c3a-9a51-0c1d2e3f4a5b');

        try {
            $this->executor->execute($this->actor, $key, 'sales.create', ['qty' => '35'], fn () => throw new RuntimeException('stock lock failed'));
            $this->fail('Expected failure');
        } catch (RuntimeException) {
        }

        $retry = $this->executor->execute($this->actor, $key, 'sales.create', ['qty' => '35'], fn () => new CommandResult(201, ['ok' => true]));

        $this->assertFalse($retry->replayed, 'retry after a rolled-back attempt must execute');
    }

    public function test_key_format_is_validated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IdempotencyKey::fromString('short');
    }
}

/** Minimal transaction fake: snapshot/restore of the in-memory store. */
final class InMemoryTransactions implements TransactionManager
{
    private int $level = 0;
    /** @var list<callable(): void> */
    private array $onRollback = [];

    public function run(callable $work): mixed
    {
        $this->level++;
        try {
            $result = $work();
            $this->level--;
            if ($this->level === 0) {
                $this->onRollback = [];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->level--;
            if ($this->level === 0) {
                foreach (array_reverse($this->onRollback) as $undo) {
                    $undo();
                }
                $this->onRollback = [];
            }
            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    public function onRollback(callable $undo): void
    {
        $this->onRollback[] = $undo;
    }
}

final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{id: string, command: string, hash: string, result: ?CommandResult}> */
    private array $rows = [];

    public function __construct(private readonly InMemoryTransactions $tx)
    {
    }

    public function claim(?string $actorUserId, IdempotencyKey $key, string $command, string $requestHash): IdempotencyClaim
    {
        $k = ($actorUserId ?? 'system').'|'.$key->value;
        if (isset($this->rows[$k])) {
            $row = $this->rows[$k];

            return IdempotencyClaim::replay($row['id'], $row['command'], $row['hash'], $row['result']);
        }

        $id = bin2hex(random_bytes(8));
        $this->rows[$k] = ['id' => $id, 'command' => $command, 'hash' => $requestHash, 'result' => null];
        $this->tx->onRollback(function () use ($k): void {
            unset($this->rows[$k]);
        });

        return IdempotencyClaim::fresh($id, $command, $requestHash);
    }

    public function complete(string $recordId, CommandResult $result): void
    {
        foreach ($this->rows as $k => $row) {
            if ($row['id'] === $recordId) {
                $this->rows[$k]['result'] = $result;
            }
        }
    }
}
