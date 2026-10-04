<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Shared\Identity\IdentityGenerator;
use App\Domain\Shared\Identity\Id;
use Illuminate\Support\Str;

final class UuidV7IdentityGenerator implements IdentityGenerator
{
    public function next(): Id
    {
        return Id::fromString((string) Str::uuid7());
    }
}
