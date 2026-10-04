<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class EntityNotFound extends DomainException
{
    public static function of(string $entity, string $id): self
    {
        return new self("{$entity} not found.", 'NOT_FOUND', ['entity' => $entity, 'id' => $id]);
    }
}
