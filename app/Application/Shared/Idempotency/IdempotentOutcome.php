<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

final class IdempotentOutcome
{
    public function __construct(
        public readonly CommandResult $result,
        public readonly bool $replayed,
    ) {
    }
}
