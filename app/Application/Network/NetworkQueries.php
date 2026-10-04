<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Query\StatusFilter;
use App\Domain\Network\Location;
use App\Domain\Network\Node;
use App\Domain\Network\NodeCapability;

interface NetworkQueries
{
    /** @return list<Location> (small set, no pagination) */
    public function locations(StatusFilter $status): array;

    /** @return list<Node> */
    public function nodes(StatusFilter $status, ?NodeCapability $capability, ?string $locationId): array;
}
