<?php

declare(strict_types=1);

namespace App\Domain\Partner;

use App\Domain\Shared\Text\Notes;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Contact\EmailAddress;
use App\Domain\Shared\Contact\PhoneNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Model\AggregateRoot;
use App\Domain\Shared\Text\Name;

/**
 * A person or organisation DG Way does business with.
 *
 * One identity (name, contact, address) with one or more roles:
 *   SUPPLIER  → SupplierRole (payment terms DG Way receives, notes)
 *   CUSTOMER  → CustomerRole (type, payment terms / credit limit, notes)
 *
 * This avoids duplicating the same pengepul as a "supplier record" and a
 * "customer record" with diverging phone numbers. Roles are never removed,
 * only deactivated, so history stays attributable.
 *
 * Usable as a supplier  ⇔ partner active AND supplier role active.
 * Usable as a customer  ⇔ partner active AND customer role active.
 */
final class BusinessPartner extends AggregateRoot
{
    private function __construct(
        Id $id,
        int $version,
        private readonly BusinessCode $code,
        private Name $name,
        private ?Name $legalName,
        private readonly PartyType $partyType,
        private ?PhoneNumber $phone,
        private ?EmailAddress $email,
        private ?Address $address,
        private ?string $notes,
        private bool $active,
        private ?SupplierRole $supplierRole,
        private ?CustomerRole $customerRole,
    ) {
        parent::__construct($id, $version);
    }

    public static function register(
        Id $id,
        BusinessCode $code,
        Name $name,
        PartyType $partyType,
        ?SupplierRole $supplierRole = null,
        ?CustomerRole $customerRole = null,
        ?Name $legalName = null,
        ?PhoneNumber $phone = null,
        ?EmailAddress $email = null,
        ?Address $address = null,
        ?string $notes = null,
    ): self {
        if ($supplierRole === null && $customerRole === null) {
            throw InvariantViolation::because('PARTNER_ROLE_REQUIRED', 'A business partner needs at least one role (SUPPLIER and/or CUSTOMER).');
        }

        return new self($id, 0, $code, $name, $legalName, $partyType, $phone, $email, $address, Notes::clean($notes), true, $supplierRole, $customerRole);
    }

    public static function reconstitute(
        Id $id,
        int $version,
        BusinessCode $code,
        Name $name,
        ?Name $legalName,
        PartyType $partyType,
        ?PhoneNumber $phone,
        ?EmailAddress $email,
        ?Address $address,
        ?string $notes,
        bool $active,
        ?SupplierRole $supplierRole,
        ?CustomerRole $customerRole,
    ): self {
        return new self($id, $version, $code, $name, $legalName, $partyType, $phone, $email, $address, $notes, $active, $supplierRole, $customerRole);
    }

    // ── identity & contact ────────────────────────────────────────────────

    public function rename(Name $name): bool
    {
        if ($this->name->equals($name)) {
            return false;
        }
        $this->name = $name;

        return true;
    }

    public function changeLegalName(?Name $legalName): bool
    {
        if ($this->legalName?->value === $legalName?->value) {
            return false;
        }
        $this->legalName = $legalName;

        return true;
    }

    public function changeContact(?PhoneNumber $phone, ?EmailAddress $email): bool
    {
        if ($this->phone?->e164 === $phone?->e164 && $this->email?->value === $email?->value) {
            return false;
        }
        $this->phone = $phone;
        $this->email = $email;

        return true;
    }

    public function relocate(?Address $address): bool
    {
        if ($this->address === null && $address === null) {
            return false;
        }
        if ($this->address !== null && $address !== null && $this->address->equals($address)) {
            return false;
        }
        $this->address = $address;

        return true;
    }

    public function changeNotes(?string $notes): bool
    {
        $notes = Notes::clean($notes);
        if ($this->notes === $notes) {
            return false;
        }
        $this->notes = $notes;

        return true;
    }

    // ── lifecycle ─────────────────────────────────────────────────────────

    public function activate(): bool
    {
        if ($this->active) {
            return false;
        }
        $this->active = true;

        return true;
    }

    /** Hides the partner from new transactions; history keeps referencing it. */
    public function deactivate(): bool
    {
        if (! $this->active) {
            return false;
        }
        $this->active = false;

        return true;
    }

    // ── roles ─────────────────────────────────────────────────────────────

    public function becomeSupplier(SupplierRole $role): void
    {
        if ($this->supplierRole !== null) {
            throw InvariantViolation::because('ROLE_ALREADY_ASSIGNED', "{$this->code->value} is already a supplier.");
        }
        $this->supplierRole = $role;
    }

    public function becomeCustomer(CustomerRole $role): void
    {
        if ($this->customerRole !== null) {
            throw InvariantViolation::because('ROLE_ALREADY_ASSIGNED', "{$this->code->value} is already a customer.");
        }
        $this->customerRole = $role;
    }

    public function changeSupplierTerms(PaymentTerms $terms): bool
    {
        return $this->supplier()->changeTerms($terms);
    }

    /** Change tempo and/or credit limit of the customer role. */
    public function changeCreditTerms(PaymentTerms $terms): bool
    {
        return $this->customer()->changeTerms($terms);
    }

    public function reclassifyCustomer(CustomerType $type): bool
    {
        return $this->customer()->reclassify($type);
    }

    public function changeRoleNotes(PartnerRole $role, ?string $notes): bool
    {
        return match ($role) {
            PartnerRole::SUPPLIER => $this->supplier()->changeNotes($notes),
            PartnerRole::CUSTOMER => $this->customer()->changeNotes($notes),
        };
    }

    public function activateRole(PartnerRole $role): bool
    {
        return match ($role) {
            PartnerRole::SUPPLIER => $this->supplier()->setActive(true),
            PartnerRole::CUSTOMER => $this->customer()->setActive(true),
        };
    }

    public function deactivateRole(PartnerRole $role): bool
    {
        return match ($role) {
            PartnerRole::SUPPLIER => $this->supplier()->setActive(false),
            PartnerRole::CUSTOMER => $this->customer()->setActive(false),
        };
    }

    public function hasRole(PartnerRole $role): bool
    {
        return match ($role) {
            PartnerRole::SUPPLIER => $this->supplierRole !== null,
            PartnerRole::CUSTOMER => $this->customerRole !== null,
        };
    }

    /** Can DG Way buy from this partner right now? */
    public function isActiveSupplier(): bool
    {
        return $this->active && $this->supplierRole?->isActive() === true;
    }

    /** Can DG Way sell to this partner right now? */
    public function isActiveCustomer(): bool
    {
        return $this->active && $this->customerRole?->isActive() === true;
    }

    // ── accessors ─────────────────────────────────────────────────────────

    public function code(): BusinessCode
    {
        return $this->code;
    }

    public function name(): Name
    {
        return $this->name;
    }

    public function legalName(): ?Name
    {
        return $this->legalName;
    }

    public function partyType(): PartyType
    {
        return $this->partyType;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function email(): ?EmailAddress
    {
        return $this->email;
    }

    public function address(): ?Address
    {
        return $this->address;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function supplierRole(): ?SupplierRole
    {
        return $this->supplierRole;
    }

    public function customerRole(): ?CustomerRole
    {
        return $this->customerRole;
    }

    public function snapshot(): array
    {
        return [
            'code' => $this->code->value,
            'name' => $this->name->value,
            'legal_name' => $this->legalName?->value,
            'party_type' => $this->partyType->value,
            'phone' => $this->phone?->e164,
            'email' => $this->email?->value,
            'address' => $this->address?->jsonSerialize(),
            'notes' => $this->notes,
            'is_active' => $this->active,
            'supplier' => $this->supplierRole?->snapshot(),
            'customer' => $this->customerRole?->snapshot(),
        ];
    }

    private function supplier(): SupplierRole
    {
        return $this->supplierRole ?? throw InvariantViolation::because('ROLE_NOT_ASSIGNED', "{$this->code->value} is not a supplier.");
    }

    private function customer(): CustomerRole
    {
        return $this->customerRole ?? throw InvariantViolation::because('ROLE_NOT_ASSIGNED', "{$this->code->value} is not a customer.");
    }
}
