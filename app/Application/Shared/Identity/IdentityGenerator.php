<?php

declare(strict_types=1);

namespace App\Application\Shared\Identity;

use App\Domain\Shared\Identity\Id;

/** Issues new entity identities (UUIDv7: time-ordered, index-friendly). */
interface IdentityGenerator
{
    public function next(): Id;
}
