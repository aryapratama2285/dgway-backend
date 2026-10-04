<?php

declare(strict_types=1);

namespace App\Application\Shared\Audit;

/**
 * Append-only audit trail port.
 *
 * Implementations MUST write within the caller's current transaction, so an
 * audit record exists if and only if the audited change was committed.
 */
interface AuditRecorder
{
    /** @return string UUID of the stored audit record */
    public function record(AuditEntry $entry): string;
}
