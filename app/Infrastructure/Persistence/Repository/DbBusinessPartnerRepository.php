<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\BusinessPartnerRepository;
use App\Domain\Partner\CustomerRole;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PartnerRole;
use App\Domain\Partner\PartyType;
use App\Domain\Partner\PaymentTerms;
use App\Domain\Partner\SupplierRole;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Contact\EmailAddress;
use App\Domain\Shared\Contact\PhoneNumber;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Text\Name;
use Illuminate\Support\Str;

/**
 * Persists the BusinessPartner aggregate across two tables:
 * business_partners (identity) + business_partner_roles (one row per role).
 * Roles are upserted, never deleted.
 */
final class DbBusinessPartnerRepository implements BusinessPartnerRepository
{
    private const TABLE = 'business_partners';
    private const ROLES = 'business_partner_roles';
    private const LABEL = 'Business partner';

    public function __construct(private readonly RepositorySupport $support)
    {
    }

    public function get(Id $id): BusinessPartner
    {
        return $this->load($id, false);
    }

    public function getForUpdate(Id $id): BusinessPartner
    {
        return $this->load($id, true);
    }

    public function add(BusinessPartner $partner): void
    {
        $this->support->insert(self::TABLE, self::row($partner), $partner, self::LABEL, 'business_partners_code_unique', $partner->code()->value);
        $this->writeRoles($partner);
    }

    public function save(BusinessPartner $partner): void
    {
        $this->support->update(self::TABLE, self::row($partner), $partner, self::LABEL);
        $this->writeRoles($partner);
    }

    /** @param list<object> $roleRows */
    public static function hydrate(object $r, array $roleRows): BusinessPartner
    {
        $supplier = null;
        $customer = null;
        foreach ($roleRows as $role) {
            $terms = PaymentTerms::of((int) $role->payment_term_days, $role->credit_limit === null ? null : Money::of((string) $role->credit_limit));
            if ($role->role === PartnerRole::SUPPLIER->value) {
                $supplier = SupplierRole::reconstitute($terms, $role->notes, (bool) $role->is_active);
            } else {
                $customer = CustomerRole::reconstitute(CustomerType::from($role->customer_type), $terms, $role->notes, (bool) $role->is_active);
            }
        }

        return BusinessPartner::reconstitute(
            Id::fromString($r->id),
            (int) $r->version,
            BusinessCode::of($r->code),
            Name::of($r->name),
            $r->legal_name === null ? null : Name::of($r->legal_name),
            PartyType::from($r->party_type),
            $r->phone === null ? null : PhoneNumber::of($r->phone),
            $r->email === null ? null : EmailAddress::of($r->email),
            AddressColumns::fromRow($r),
            $r->notes,
            (bool) $r->is_active,
            $supplier,
            $customer,
        );
    }

    private function load(Id $id, bool $lock): BusinessPartner
    {
        $row = $this->support->fetch(self::TABLE, $id->value, self::LABEL, $lock);
        $roles = $this->support->db()->table(self::ROLES)->where('partner_id', $id->value)->get()->all();

        return self::hydrate($row, $roles);
    }

    private function writeRoles(BusinessPartner $p): void
    {
        $rows = [];
        if (($s = $p->supplierRole()) !== null) {
            $rows[] = ['role' => PartnerRole::SUPPLIER->value, 'is_active' => $s->isActive(), 'payment_term_days' => $s->terms()->termDays, 'credit_limit' => null, 'customer_type' => null, 'notes' => $s->notes()];
        }
        if (($c = $p->customerRole()) !== null) {
            $rows[] = ['role' => PartnerRole::CUSTOMER->value, 'is_active' => $c->isActive(), 'payment_term_days' => $c->terms()->termDays, 'credit_limit' => $c->terms()->creditLimit?->toStorage(), 'customer_type' => $c->type()->value, 'notes' => $c->notes()];
        }

        foreach ($rows as $row) {
            $this->support->db()->table(self::ROLES)->upsert(
                [$row + ['id' => (string) Str::uuid7(), 'partner_id' => $p->id()->value, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]],
                ['partner_id', 'role'],
                ['is_active', 'payment_term_days', 'credit_limit', 'customer_type', 'notes', 'updated_at'],
            );
        }
    }

    /** @return array<string, mixed> */
    private static function row(BusinessPartner $p): array
    {
        return [
            'code' => $p->code()->value,
            'name' => $p->name()->value,
            'legal_name' => $p->legalName()?->value,
            'party_type' => $p->partyType()->value,
            'phone' => $p->phone()?->e164,
            'email' => $p->email()?->value,
            'notes' => $p->notes(),
            'is_active' => $p->isActive(),
        ] + AddressColumns::toRow($p->address());
    }
}
