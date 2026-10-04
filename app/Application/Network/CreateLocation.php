<?php

declare(strict_types=1);

namespace App\Application\Network;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Network\Location;
use App\Domain\Network\LocationRepository;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Text\Name;

final class CreateLocation
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param array<string, mixed>|null $address */
    public function execute(Actor $actor, string $code, string $name, ?array $address = null, ?string $notes = null): Location
    {
        return $this->tx->run(function () use ($actor, $code, $name, $address, $notes): Location {
            $location = Location::create(
                $this->ids->next(),
                BusinessCode::of($code, 'Location code'),
                Name::of($name),
                $address === null ? null : Address::fromArray($address),
                $notes,
            );
            $this->locations->add($location);
            $this->audit->created($actor, 'location.created', 'location', $location);

            return $location;
        });
    }
}
