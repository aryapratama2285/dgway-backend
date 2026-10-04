<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Shared\Document\DocumentNumberGenerator;
use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Time\BusinessDate;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Gap-free (per committed transaction) document sequence using a single
 * atomic UPSERT per (prefix, business date). The row lock taken by the UPSERT
 * serialises concurrent issuers of the same prefix/day until commit.
 */
final class PostgresDocumentNumberGenerator implements DocumentNumberGenerator
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function next(string $prefix, BusinessDate $date): DocumentNumber
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Document numbers must be issued inside a transaction.');
        }

        $prefix = DocumentNumber::normalizePrefix($prefix);

        $row = $this->connection->selectOne(
            <<<'SQL'
                INSERT INTO document_sequences (prefix, sequence_date, last_value, created_at, updated_at)
                VALUES (?, ?, 1, now(), now())
                ON CONFLICT (prefix, sequence_date)
                DO UPDATE SET last_value = document_sequences.last_value + 1, updated_at = now()
                RETURNING last_value
                SQL,
            [$prefix, $date->toString()],
        );

        return DocumentNumber::compose($prefix, $date, (int) $row->last_value);
    }
}
