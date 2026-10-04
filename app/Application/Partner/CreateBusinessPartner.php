<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Identity\IdentityGenerator;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\PartyType;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Text\Name;

/**
 * Register a partner with its initial role(s).
 *
 * @phpstan-type Input array{
 *   code: string, name: string, party_type: string, legal_name?: ?string,
 *   phone?: ?string, email?: ?string, address?: ?array<string, mixed>, notes?: ?string,
 *   supplier?: ?array<string, mixed>, customer?: ?array<string, mixed>
 * }
 */
final class CreateBusinessPartner
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly IdentityGenerator $ids,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @param Input $input */
    public function execute(Actor $actor, array $input): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $input): BusinessPartner {
            $partyType = PartyType::tryFrom(strtoupper((string) ($input['party_type'] ?? '')))
                ?? throw InvariantViolation::because('PARTY_TYPE_INVALID', 'party_type must be INDIVIDUAL or ORGANIZATION.', ['field' => 'party_type']);

            $partner = BusinessPartner::register(
                $this->ids->next(),
                BusinessCode::of((string) $input['code'], 'Partner code'),
                Name::of((string) $input['name']),
                $partyType,
                isset($input['supplier']) ? PartnerInput::supplierRole($input['supplier']) : null,
                isset($input['customer']) ? PartnerInput::customerRole($input['customer']) : null,
                PartnerInput::legalName($input['legal_name'] ?? null),
                PartnerInput::phone($input['phone'] ?? null),
                PartnerInput::email($input['email'] ?? null),
                PartnerInput::address($input['address'] ?? null),
                $input['notes'] ?? null,
            );

            $this->partners->add($partner);
            $this->audit->created($actor, 'business_partner.created', 'business_partner', $partner);

            return $partner;
        });
    }
}
