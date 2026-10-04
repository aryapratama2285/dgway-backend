<?php

declare(strict_types=1);

namespace App\Application\Shared\Command;

use App\Domain\Shared\Exception\ConcurrentModification;
use App\Domain\Shared\Model\AggregateRoot;

/**
 * Optional optimistic-concurrency guard for updates: if the client says
 * "I edited version 3", the change is rejected when the record is no longer
 * at version 3 (someone else saved in between).
 */
final class ExpectedVersion
{
    public static function check(string $entity, AggregateRoot $aggregate, ?int $expected): void
    {
        if ($expected !== null && $expected !== $aggregate->version()) {
            throw ConcurrentModification::of($entity, $aggregate->id()->value, $expected, $aggregate->version());
        }
    }
}
