<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Network\ActivateLocation;
use App\Application\Network\CreateLocation;
use App\Application\Network\DeactivateLocation;
use App\Application\Network\NetworkQueries;
use App\Application\Network\UpdateLocation;
use App\Application\Shared\Command\Patch;
use App\Domain\Network\LocationRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use App\Http\Resources\Api\V1\MasterDataResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final class LocationController extends ApiController
{
    public function index(Request $request, NetworkQueries $queries): JsonResponse
    {
        return $this->listed($queries->locations($this->status($request)), MasterDataResources::location(...));
    }

    public function show(string $id, LocationRepository $locations): JsonResponse
    {
        return $this->single(MasterDataResources::location($locations->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateLocation $create): JsonResponse
    {
        $v = $request->validate(['code' => R::code(), 'name' => R::name(), 'notes' => R::notes(), ...R::address('address')]);

        return $this->command($request, 'location.create',
            fn (Actor $a) => $create->execute($a, $v['code'], $v['name'], $v['address'] ?? null, $v['notes'] ?? null),
            MasterDataResources::location(...), 201, 'location');
    }

    public function update(Request $request, string $id, UpdateLocation $update): JsonResponse
    {
        $v = $request->validate(['name' => R::name(false), 'notes' => R::notes(), 'code' => ['prohibited'], 'expected_version' => R::expectedVersion(), ...R::address('address')]);
        if ($request->has('address') && $request->input('address') === null) {
            $v['address'] = null;
        }

        return $this->command($request, 'location.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), $v['expected_version'] ?? null),
            MasterDataResources::location(...));
    }

    public function activate(Request $request, string $id, ActivateLocation $activate): JsonResponse
    {
        return $this->command($request, 'location.activate', fn (Actor $a) => $activate->execute($a, $id), MasterDataResources::location(...));
    }

    public function deactivate(Request $request, string $id, DeactivateLocation $deactivate): JsonResponse
    {
        return $this->command($request, 'location.deactivate', fn (Actor $a) => $deactivate->execute($a, $id), MasterDataResources::location(...));
    }
}
