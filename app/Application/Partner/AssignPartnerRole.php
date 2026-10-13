<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\PartnerRole;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/** An existing supplier starts buying from DG Way (or vice versa). */
final class AssignPartnerRole
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    /** @param array<string, mixed> $roleData */
    public function execute(Actor $actor, string $partnerId, PartnerRole $role, array $roleData): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $partnerId, $role, $roleData): BusinessPartner {
            $partner = $this->partners->getForUpdate(Id::fromString($partnerId));
            $before = $partner->snapshot();

            if ($role === PartnerRole::CUSTOMER) {
                $customerRole = PartnerInput::customerRole($roleData);
                if (PartnerInput::grantsCredit($customerRole->terms())) {
                    $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_CUSTOMER_CREDIT);
                }
                $partner->becomeCustomer($customerRole);
            } else {
                $partner->becomeSupplier(PartnerInput::supplierRole($roleData));
            }

            $this->partners->save($partner);
            $this->audit->changed($actor, 'business_partner.role_assigned', 'business_partner', $partner, $before, ['role' => $role->value]);

            return $partner;
        });
    }
}
