<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * The record changed since the client last read it (optimistic concurrency).
 * The client must reload and re-apply its change.
 */
final class ConcurrentModification extends DomainException
{
    public static function of(string $entity, string $id, int $expected, ?int $actual = null): self
    {
        return new self(
            "{$entity} was modified by someone else. Reload and try again.",
            'CONCURRENT_MODIFICATION',
            ['entity' => $entity, 'id' => $id, 'expected_version' => $expected, 'actual_version' => $actual],
        );
    }
}
