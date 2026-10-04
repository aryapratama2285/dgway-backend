<?php

declare(strict_types=1);

namespace App\Application\Shared\Transaction;

/**
 * Transaction boundary port.
 *
 * Every state-changing use case runs its whole unit of work inside exactly
 * one call to run(): domain changes, ledger movements, audit entries and the
 * idempotency record either all commit or all roll back.
 *
 * Nested calls join the outer transaction (they do not commit on their own).
 */
interface TransactionManager
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed;

    public function inTransaction(): bool;
}
