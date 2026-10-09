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

    /** F4A: an edit of a document that is no longer DRAFT (409, same code as any stale-status action). */
    public static function notEditable(string $document, string $status): self
    {
        return new self(
            "{$document} is {$status}; only a DRAFT can be edited.",
            'INVALID_STATUS_TRANSITION',
            ['document' => $document, 'from' => $status, 'to' => $status, 'action' => 'edit'],
        );
    }
}
