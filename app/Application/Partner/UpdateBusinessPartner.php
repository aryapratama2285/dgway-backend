<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Command\ExpectedVersion;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Text\Name;

/**
 * Change a partner's identity/contact data: name, legal name, phone, email,
 * address, notes. Role terms have their own use case (ChangePartnerRole).
 */
final class UpdateBusinessPartner
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    public function execute(Actor $actor, string $partnerId, Patch $patch, ?int $expectedVersion = null): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $partnerId, $patch, $expectedVersion): BusinessPartner {
            $partner = $this->partners->getForUpdate(Id::fromString($partnerId));
            ExpectedVersion::check('Business partner', $partner, $expectedVersion);
            $before = $partner->snapshot();
            $changed = false;

            if ($patch->has('name')) {
                $changed = $partner->rename(Name::of((string) $patch->string('name'))) || $changed;
            }
            if ($patch->has('legal_name')) {
                $changed = $partner->changeLegalName(PartnerInput::legalName($patch->string('legal_name'))) || $changed;
            }
            if ($patch->has('phone') || $patch->has('email')) {
                $phone = $patch->has('phone') ? PartnerInput::phone($patch->string('phone')) : $partner->phone();
                $email = $patch->has('email') ? PartnerInput::email($patch->string('email')) : $partner->email();
                $changed = $partner->changeContact($phone, $email) || $changed;
            }
            if ($patch->has('address')) {
                $changed = $partner->relocate(PartnerInput::address($patch->array('address'))) || $changed;
            }
            if ($patch->has('notes')) {
                $changed = $partner->changeNotes($patch->string('notes')) || $changed;
            }

            if ($changed) {
                $this->partners->save($partner);
                $this->audit->changed($actor, 'business_partner.updated', 'business_partner', $partner, $before);
            }

            return $partner;
        });
    }
}
