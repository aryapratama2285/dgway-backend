<?php

declare(strict_types=1);

namespace App\Application\Shared\Query;

final class PageRequest
{
    public const MAX_PER_PAGE = 100;

    public readonly int $page;
    public readonly int $perPage;

    public function __construct(int $page = 1, int $perPage = 25)
    {
        $this->page = max(1, $page);
        $this->perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
