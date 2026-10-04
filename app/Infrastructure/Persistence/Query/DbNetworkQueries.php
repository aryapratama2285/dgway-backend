<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Query;

use App\Application\Network\NetworkQueries;
use App\Application\Shared\Query\StatusFilter;
use App\Domain\Network\NodeCapability;
use App\Infrastructure\Persistence\Repository\DbLocationRepository;
use App\Infrastructure\Persistence\Repository\DbNodeRepository;
use Illuminate\Database\ConnectionInterface;

final class DbNetworkQueries implements NetworkQueries
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function locations(StatusFilter $status): array
    {
        $q = $this->db->table('locations')->orderBy('code');
        DbCatalogQueries::applyStatus($q, $status);

        return array_map(DbLocationRepository::hydrate(...), $q->get()->all());
    }

    public function nodes(StatusFilter $status, ?NodeCapability $capability, ?string $locationId): array
    {
        $q = $this->db->table('nodes')->orderBy('code');
        DbCatalogQueries::applyStatus($q, $status);
        if ($capability !== null) {
            $q->whereRaw('? = ANY(capabilities)', [$capability->value]);
        }
        if ($locationId !== null) {
            $q->where('location_id', $locationId);
        }

        return array_map(DbNodeRepository::hydrate(...), $q->get()->all());
    }
}
