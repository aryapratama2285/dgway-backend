<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Exception\DuplicateCode;
use App\Domain\Shared\Exception\EntityNotFound;
use App\Domain\Shared\Model\AggregateRoot;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * Shared persistence mechanics for master-data repositories:
 *
 * - INSERT relying on the UNIQUE index; a 23505 violation on the code
 *   column becomes DuplicateCode (safe under concurrency — no check-then-insert).
 * - Optimistic-concurrency UPDATE: `WHERE id = ? AND version = ?`, version + 1.
 * - Row loading with optional `FOR UPDATE` lock.
 */
final class RepositorySupport
{
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    public function fetch(string $table, string $id, string $entityLabel, bool $lock = false): object
    {
        $query = $this->db->table($table)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw EntityNotFound::of($entityLabel, $id);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  string  $uniqueConstraint  e.g. products_sku_unique
     */
    public function insert(string $table, array $row, AggregateRoot $entity, string $entityLabel, string $uniqueConstraint, string $code): void
    {
        try {
            $this->db->table($table)->insert($row + [
                'id' => $entity->id()->value,
                'version' => 1,
                'created_at' => $this->db->raw('now()'),
                'updated_at' => $this->db->raw('now()'),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e, $uniqueConstraint)) {
                throw DuplicateCode::of($entityLabel, $code);
            }
            throw $e;
        }

        $entity->syncVersion(1);
    }

    /** @param array<string, mixed> $row */
    public function update(string $table, array $row, AggregateRoot $entity, string $entityLabel): void
    {
        $expected = $entity->version();
        $affected = $this->db->table($table)
            ->where('id', $entity->id()->value)
            ->where('version', $expected)
            ->update($row + [
                'version' => $expected + 1,
                'updated_at' => $this->db->raw('now()'),
            ]);

        if ($affected !== 1) {
            $actual = $this->db->table($table)->where('id', $entity->id()->value)->value('version');
            throw ConcurrentModification::of($entityLabel, $entity->id()->value, $expected, $actual === null ? null : (int) $actual);
        }

        $entity->syncVersion($expected + 1);
    }

    public function isUniqueViolation(QueryException $e, string $constraint): bool
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === self::UNIQUE_VIOLATION
            && str_contains($e->getMessage(), $constraint);
    }
}
