<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

/**
 * A master record with the same unique code already exists.
 * Raised by repositories when PostgreSQL reports a UNIQUE violation, so it is
 * reliable under concurrent requests (no check-then-insert race).
 */
final class DuplicateCode extends DomainException
{
    public static function of(string $entity, string $code): self
    {
        return new self("{$entity} with code '{$code}' already exists.", 'DUPLICATE_CODE', ['entity' => $entity, 'code' => $code]);
    }
}
