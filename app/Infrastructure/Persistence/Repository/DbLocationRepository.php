<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Network\Location;
use App\Domain\Network\LocationRepository;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

final class DbLocationRepository implements LocationRepository
{
    private const TABLE = 'locations';
    private const LABEL = 'Location';

    public function __construct(private readonly RepositorySupport $support)
    {
    }

    public function get(Id $id): Location
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL));
    }

    public function getForUpdate(Id $id): Location
    {
        return self::hydrate($this->support->fetch(self::TABLE, $id->value, self::LABEL, lock: true));
    }

    public function add(Location $location): void
    {
        $this->support->insert(self::TABLE, self::row($location), $location, self::LABEL, 'locations_code_unique', $location->code()->value);
    }

    public function save(Location $location): void
    {
        $this->support->update(self::TABLE, self::row($location), $location, self::LABEL);
    }

    public static function hydrate(object $r): Location
    {
        return Location::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            BusinessCode::of($r->code),
            Name::of($r->name),
            AddressColumns::fromRow($r),
            $r->notes,
            (bool) $r->is_active,
        );
    }

    /** @return array<string, mixed> */
    private static function row(Location $l): array
    {
        return [
            'code' => $l->code()->value,
            'name' => $l->name()->value,
            'notes' => $l->notes(),
            'is_active' => $l->isActive(),
        ] + AddressColumns::toRow($l->address());
    }
}
