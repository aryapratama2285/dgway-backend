<?php

declare(strict_types=1);

namespace App\Application\Shared\Idempotency;

/**
 * Persistence port for idempotency records.
 *
 * Both methods MUST run inside the caller's transaction. Because the record is
 * written in the same transaction as the business change, a key is only ever
 * "used" if the business change committed; a failed attempt leaves no trace
 * and can be retried with the same key.
 */
interface IdempotencyStore
{
    /**
     * Atomically claim the key for this actor. If another request holds the
     * same key concurrently, this call blocks until that request commits or
     * rolls back, then returns a replay or a fresh claim accordingly.
     */
    public function claim(?string $actorUserId, IdempotencyKey $key, string $command, string $requestHash): IdempotencyClaim;

    public function complete(string $recordId, CommandResult $result): void;
}
