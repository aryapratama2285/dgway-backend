<?php

declare(strict_types=1);

namespace App\Domain\Shared\Model;

use App\Domain\Shared\Identity\Id;

/**
 * Base for aggregate roots: identity + optimistic-concurrency version.
 *
 * The version starts at 1 when first stored and is incremented by the
 * repository on every successful save. A save whose loaded version no longer
 * matches the stored one is rejected (ConcurrentModification).
 */
abstract class AggregateRoot
{
    protected function __construct(
        private readonly Id $id,
        private int $version,
    ) {
    }

    public function id(): Id
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @internal Called only by repositories after a successful write.
     */
    public function syncVersion(int $version): void
    {
        $this->version = $version;
    }

    /**
     * Compact, primitive representation of the business state, used for
     * audit before/after snapshots. Not a persistence format.
     *
     * @return array<string, mixed>
     */
    abstract public function snapshot(): array;
}
