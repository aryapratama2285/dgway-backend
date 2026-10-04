<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Shared\Identity\Id;

interface LocationRepository
{
    public function get(Id $id): Location;

    public function getForUpdate(Id $id): Location;

    public function add(Location $location): void;

    public function save(Location $location): void;
}
