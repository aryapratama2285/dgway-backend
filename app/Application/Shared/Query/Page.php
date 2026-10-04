<?php

declare(strict_types=1);

namespace App\Application\Shared\Query;

/**
 * @template T
 */
final class Page
{
    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly PageRequest $request,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->request->perPage));
    }
}
