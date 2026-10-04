<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConcurrencyErrorDetector as LaravelDetector;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Decides which database errors make a transaction safe to retry.
 *
 * Laravel's default detector matches error *messages* and the code of a raw
 * PDOException. PostgreSQL errors reach us wrapped in QueryException, and the
 * serialization-failure message ("could not serialize access …") is not in
 * Laravel's list, so serialization failures would NOT be retried. This
 * detector checks the SQLSTATE instead:
 *
 *   40001 serialization_failure
 *   40P01 deadlock_detected
 */
final class PostgresConcurrencyErrorDetector implements ConcurrencyErrorDetector
{
    private const RETRYABLE = ['40001', '40P01'];

    public function causedByConcurrencyError(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $state = match (true) {
                $cur instanceof QueryException => (string) ($cur->errorInfo[0] ?? $cur->getCode()),
                $cur instanceof PDOException => (string) ($cur->errorInfo[0] ?? $cur->getCode()),
                default => null,
            };
            if ($state !== null && in_array($state, self::RETRYABLE, true)) {
                return true;
            }
        }

        return (new LaravelDetector())->causedByConcurrencyError($e);
    }
}
