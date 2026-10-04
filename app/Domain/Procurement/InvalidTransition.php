<?php

declare(strict_types=1);

namespace App\Domain\Procurement;

use App\Domain\Shared\Exception\ConflictsWithCurrentState;
use App\Domain\Shared\Exception\DomainException;

final class InvalidTransition extends DomainException implements ConflictsWithCurrentState
{
    public static function of(string $document, string $from, string $to, string $why = ''): self
    {
        return new self(
            trim("{$document} cannot go from {$from} to {$to}. {$why}"),
            'INVALID_STATUS_TRANSITION',
            ['document' => $document, 'from' => $from, 'to' => $to],
        );
    }
}
