<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

final class DeactivateBusinessPartner
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $partnerId): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $partnerId): BusinessPartner {
            $partner = $this->partners->getForUpdate(Id::fromString($partnerId));
            $before = $partner->snapshot();
            if ($partner->deactivate()) {
                $this->partners->save($partner);
                $this->audit->changed($actor, 'business_partner.deactivated', 'business_partner', $partner, $before);
            }

            return $partner;
        });
    }
}
