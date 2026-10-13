<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Audit\AuditTrail;
use App\Application\Shared\Security\Authorizer;
use App\Application\Shared\Security\Permission;
use App\Application\Shared\Command\Patch;
use App\Application\Shared\Transaction\TransactionManager;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\PartnerRole;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Identity\Id;

/**
 * Change the commercial terms of a role:
 *   customer → changeCreditTerms (tempo / credit limit), reclassifyCustomer, notes
 *   supplier → changeSupplierTerms (tempo), notes
 */
final class ChangePartnerRole
{
    public function __construct(
        private readonly BusinessPartnerRepository $partners,
        private readonly TransactionManager $tx,
        private readonly AuditTrail $audit,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function execute(Actor $actor, string $partnerId, PartnerRole $role, Patch $patch): BusinessPartner
    {
        return $this->tx->run(function () use ($actor, $partnerId, $role, $patch): BusinessPartner {
            $partner = $this->partners->getForUpdate(Id::fromString($partnerId));
            $before = $partner->snapshot();
            $changed = false;

            if ($role === PartnerRole::CUSTOMER) {
                $current = $partner->customerRole();
                if ($patch->has('customer_type')) {
                    $changed = $partner->reclassifyCustomer(PartnerInput::customerType($patch->get('customer_type'))) || $changed;
                }
                if ($patch->has('term_days') || $patch->has('credit_limit')) {
                    $data = array_filter(['term_days' => $patch->get('term_days'), 'credit_limit' => $patch->get('credit_limit')], fn ($k) => $patch->has($k), ARRAY_FILTER_USE_KEY);
                    $terms = PartnerInput::terms($data, $current?->terms());
                    if ($current === null || ! $terms->equals($current->terms())) {
                        // F5A: any change of a customer's credit terms (granting, raising,
                        // lowering or removing credit) is an owner decision.
                        $this->authorizer->authorizeIfOperator($actor, Permission::MANAGE_CUSTOMER_CREDIT);
                    }
                    $changed = $partner->changeCreditTerms($terms) || $changed;
                }
            } else {
                if ($patch->has('term_days')) {
                    $changed = $partner->changeSupplierTerms(PartnerInput::terms(['term_days' => $patch->get('term_days')])) || $changed;
                }
            }
            if ($patch->has('notes')) {
                $changed = $partner->changeRoleNotes($role, $patch->string('notes')) || $changed;
            }

            if ($changed) {
                $this->partners->save($partner);
                $this->audit->changed($actor, 'business_partner.role_updated', 'business_partner', $partner, $before, ['role' => $role->value]);
            }

            return $partner;
        });
    }
}
