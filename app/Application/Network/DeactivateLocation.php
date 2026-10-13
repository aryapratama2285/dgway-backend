<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\Location;
use App\Domain\Network\LocationRepository;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class DeactivateLocation
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly NodeRepository $nodes,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $locationId): Location
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_NETWORK);

        return $this->tx->run(function () use ($actor, $locationId): Location {
            $location = $this->locations->getForUpdate(Id::fromString($locationId));
            $before = $location->snapshot();
            if ($location->deactivate($this->nodes->countActiveAtLocation($location->id()))) {
                $this->locations->save($location);
                $this->audit->changed($actor, 'location.deactivated', 'location', $location, $before);
            }

            return $location;
        });
    }
}
