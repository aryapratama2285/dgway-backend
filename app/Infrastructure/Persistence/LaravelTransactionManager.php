<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\Transaction\TransactionManager;
use Illuminate\Database\ConnectionInterface;

/**
 * Transaction boundary backed by the PostgreSQL connection.
 *
 * - Outermost call opens a transaction and retries up to 3 times when
 *   PostgreSQL reports a deadlock / serialization failure (the whole unit of
 *   work is re-run from scratch, which is safe because nothing committed).
 * - Nested calls join the outer transaction; only the outermost commits.
 */
final class LaravelTransactionManager implements TransactionManager
{
    private const ATTEMPTS = 3;

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function run(callable $work): mixed
    {
        if ($this->inTransaction()) {
            return $work();
        }

        return $this->connection->transaction(fn () => $work(), self::ATTEMPTS);
    }

    public function inTransaction(): bool
    {
        return $this->connection->transactionLevel() > 0;
    }
}
