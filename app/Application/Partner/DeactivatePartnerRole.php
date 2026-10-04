<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\PartnerRole;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/** e.g. stop buying from a supplier while still selling to them. */
final class DeactivatePartnerRole
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $partnerId, PartnerRole $role): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $partnerId, $role): BusinessPartner {
            $partner = $this->partners->getForUpdate(Id::fromString($partnerId));
            $before = $partner->snapshot();
            if ($partner->deactivateRole($role)) {
                $this->partners->save($partner);
                $this->audit->changed($actor, 'business_partner.role_deactivated', 'business_partner', $partner, $before, ['role' => $role->value]);
            }

            return $partner;
        });
    }
}
