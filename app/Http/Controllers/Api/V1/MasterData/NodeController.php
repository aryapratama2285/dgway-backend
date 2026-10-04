<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Network\ActivateNode;
use App\Application\Network\CreateNode;
use App\Application\Network\DeactivateNode;
use App\Application\Network\NetworkQueries;
use App\Application\Network\UpdateNode;
use App\Application\Shared\Command\Patch;
use App\Domain\Network\NodeCapability;
use App\Domain\Network\NodeRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use App\Http\Resources\Api\V1\MasterDataResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class NodeController extends ApiController
{
    public function index(Request $request, NetworkQueries $queries): JsonResponse
    {
        $request->validate(['capability' => ['nullable', Rule::enum(NodeCapability::class)], 'location_id' => ['nullable', 'uuid']]);

        return $this->listed(
            $queries->nodes($this->status($request), NodeCapability::tryFrom((string) $request->query('capability')), $request->query('location_id')),
            MasterDataResources::node(...),
        );
    }

    public function show(string $id, NodeRepository $nodes): JsonResponse
    {
        return $this->single(MasterDataResources::node($nodes->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateNode $create): JsonResponse
    {
        $v = $request->validate([
            'code' => R::code(),
            'name' => R::name(),
            'location_id' => ['nullable', 'uuid'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', 'max:32'],
            'notes' => R::notes(),
        ]);

        return $this->command($request, 'node.create',
            fn (Actor $a) => $create->execute($a, $v['code'], $v['name'], $v['location_id'] ?? null, $v['capabilities'], $v['notes'] ?? null),
            MasterDataResources::node(...), 201, 'node');
    }

    public function update(Request $request, string $id, UpdateNode $update): JsonResponse
    {
        $v = $request->validate([
            'name' => R::name(false),
            'location_id' => ['sometimes', 'nullable', 'uuid'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'max:32'],
            'notes' => R::notes(),
            'code' => ['prohibited'],
            'expected_version' => R::expectedVersion(),
        ]);

        return $this->command($request, 'node.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), $v['expected_version'] ?? null),
            MasterDataResources::node(...));
    }

    public function activate(Request $request, string $id, ActivateNode $activate): JsonResponse
    {
        return $this->command($request, 'node.activate', fn (Actor $a) => $activate->execute($a, $id), MasterDataResources::node(...));
    }

    public function deactivate(Request $request, string $id, DeactivateNode $deactivate): JsonResponse
    {
        return $this->command($request, 'node.deactivate', fn (Actor $a) => $deactivate->execute($a, $id), MasterDataResources::node(...));
    }
}
