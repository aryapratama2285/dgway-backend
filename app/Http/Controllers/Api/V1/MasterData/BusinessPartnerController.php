<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Application\Partner\ActivateBusinessPartner;
use App\Application\Partner\ActivatePartnerRole;
use App\Application\Partner\AssignPartnerRole;
use App\Application\Partner\ChangePartnerRole;
use App\Application\Partner\CreateBusinessPartner;
use App\Application\Partner\DeactivateBusinessPartner;
use App\Application\Partner\DeactivatePartnerRole;
use App\Application\Partner\PartnerQueries;
use App\Application\Partner\UpdateBusinessPartner;
use App\Application\Shared\Command\Patch;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PartnerRole;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MasterData\MasterDataRules as R;
use App\Http\Resources\Api\V1\MasterDataResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class BusinessPartnerController extends ApiController
{
    private const CUSTOMER_TYPES = ['RETAIL', 'UMKM', 'CATERING', 'RESTAURANT', 'RESELLER', 'CORPORATE', 'WALK_IN', 'OTHER'];

    public function index(Request $request, PartnerQueries $queries): JsonResponse
    {
        $request->validate([
            'role' => ['nullable', Rule::in(['SUPPLIER', 'CUSTOMER', 'supplier', 'customer'])],
            'customer_type' => ['nullable', Rule::in(self::CUSTOMER_TYPES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->paged($queries->partners(
            $this->status($request),
            $request->query('role') ? PartnerRole::from(strtoupper((string) $request->query('role'))) : null,
            $request->query('customer_type') ? CustomerType::from((string) $request->query('customer_type')) : null,
            $request->query('q'),
            $this->pageRequest($request),
        ), MasterDataResources::partner(...));
    }

    public function show(string $id, BusinessPartnerRepository $partners): JsonResponse
    {
        return $this->single(MasterDataResources::partner($partners->get(Id::fromString($id))));
    }

    public function store(Request $request, CreateBusinessPartner $create): JsonResponse
    {
        $v = $request->validate([
            'code' => R::code(),
            'name' => R::name(),
            'party_type' => ['required', Rule::in(['INDIVIDUAL', 'ORGANIZATION'])],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:255'],
            'notes' => R::notes(),
            'supplier' => ['nullable', 'array', 'required_without:customer'],
            'customer' => ['nullable', 'array', 'required_without:supplier'],
            'customer.customer_type' => ['required_with:customer', Rule::in(self::CUSTOMER_TYPES)],
            ...R::address('address'),
            ...R::terms('supplier.'),
            ...R::terms('customer.'),
        ]);

        return $this->command($request, 'business_partner.create', fn (Actor $a) => $create->execute($a, $v),
            MasterDataResources::partner(...), 201, 'business_partner');
    }

    public function update(Request $request, string $id, UpdateBusinessPartner $update): JsonResponse
    {
        $v = $request->validate([
            'name' => R::name(false),
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => R::notes(),
            'code' => ['prohibited'],
            'party_type' => ['prohibited'],
            'expected_version' => R::expectedVersion(),
            ...R::address('address'),
        ]);
        if ($request->has('address') && $request->input('address') === null) {
            $v['address'] = null;
        }

        return $this->command($request, 'business_partner.update',
            fn (Actor $a) => $update->execute($a, $id, new Patch(Arr::except($v, ['expected_version'])), $v['expected_version'] ?? null),
            MasterDataResources::partner(...));
    }

    public function activate(Request $request, string $id, ActivateBusinessPartner $activate): JsonResponse
    {
        return $this->command($request, 'business_partner.activate', fn (Actor $a) => $activate->execute($a, $id), MasterDataResources::partner(...));
    }

    public function deactivate(Request $request, string $id, DeactivateBusinessPartner $deactivate): JsonResponse
    {
        return $this->command($request, 'business_partner.deactivate', fn (Actor $a) => $deactivate->execute($a, $id), MasterDataResources::partner(...));
    }

    public function assignRole(Request $request, string $id, AssignPartnerRole $assign): JsonResponse
    {
        $v = $request->validate([
            'role' => ['required', Rule::in(['SUPPLIER', 'CUSTOMER'])],
            'customer_type' => ['required_if:role,CUSTOMER', 'nullable', Rule::in(self::CUSTOMER_TYPES)],
            ...R::terms(),
        ]);

        return $this->command($request, 'business_partner.assign_role',
            fn (Actor $a) => $assign->execute($a, $id, PartnerRole::from($v['role']), Arr::except($v, ['role'])),
            MasterDataResources::partner(...));
    }

    public function updateRole(Request $request, string $id, string $role, ChangePartnerRole $change): JsonResponse
    {
        $partnerRole = self::role($role);
        $v = $request->validate([
            'customer_type' => ['sometimes', Rule::in(self::CUSTOMER_TYPES)],
            ...R::terms(),
        ]);

        return $this->command($request, 'business_partner.update_role',
            fn (Actor $a) => $change->execute($a, $id, $partnerRole, new Patch($v)),
            MasterDataResources::partner(...));
    }

    public function activateRole(Request $request, string $id, string $role, ActivatePartnerRole $activate): JsonResponse
    {
        $partnerRole = self::role($role);

        return $this->command($request, 'business_partner.activate_role', fn (Actor $a) => $activate->execute($a, $id, $partnerRole), MasterDataResources::partner(...));
    }

    public function deactivateRole(Request $request, string $id, string $role, DeactivatePartnerRole $deactivate): JsonResponse
    {
        $partnerRole = self::role($role);

        return $this->command($request, 'business_partner.deactivate_role', fn (Actor $a) => $deactivate->execute($a, $id, $partnerRole), MasterDataResources::partner(...));
    }

    private static function role(string $role): PartnerRole
    {
        return PartnerRole::tryFrom(strtoupper($role)) ?? abort(404);
    }
}
