<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Id;

interface NodeRepository
{
    public function get(Id $id): Node;

    public function getForUpdate(Id $id): Node;

    public function findByCode(BusinessCode $code): ?Node;

    public function add(Node $node): void;

    public function save(Node $node): void;

    public function countActiveAtLocation(Id $locationId): int;
}
