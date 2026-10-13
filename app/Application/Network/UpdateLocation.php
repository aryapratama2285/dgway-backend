<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\Location;
use App\Domain\Network\LocationRepository;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class UpdateLocation
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $locationId, Patch $patch, ?int $expectedVersion = null): Location
    {
        $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_NETWORK);

        return $this->tx->run(function () use ($actor, $locationId, $patch, $expectedVersion): Location {
            $location = $this->locations->getForUpdate(Id::fromString($locationId));
            ExpectedVersion::check('Location', $location, $expectedVersion);
            $before = $location->snapshot();
            $changed = false;

            if ($patch->has('name')) {
                $changed = $location->rename(Name::of((string) $patch->string('name'))) || $changed;
            }
            if ($patch->has('address')) {
                $address = $patch->array('address');
                $changed = $location->relocate($address === null ? null : Address::fromArray($address)) || $changed;
            }
            if ($patch->has('notes')) {
                $changed = $location->changeNotes($patch->string('notes')) || $changed;
            }

            if ($changed) {
                $this->locations->save($location);
                $this->audit->changed($actor, 'location.updated', 'location', $location, $before);
            }

            return $location;
        });
    }
}
