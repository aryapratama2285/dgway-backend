<?php

declare(strict_types=1);

namespace App\Application\Shared\Query;

enum StatusFilter: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ALL = 'all';
}
