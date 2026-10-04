<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Partner;

use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\CustomerRole;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PartnerRole;
use App\Domain\Partner\PartyType;
use App\Domain\Partner\PaymentTerms;
use App\Domain\Partner\SupplierRole;
use App\Domain\Shared\Address\Address;
use App\Domain\Shared\Code\BusinessCode;
use App\Domain\Shared\Contact\PhoneNumber;
use App\Domain\Shared\Exception\InvariantViolation;
use App\Domain\Shared\Identity\Id;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Text\Name;
use PHPUnit\Framework\TestCase;

final class BusinessPartnerTest extends TestCase
{
    private function customer(CustomerType $type = CustomerType::RESTAURANT, ?PaymentTerms $terms = null): BusinessPartner
    {
        return BusinessPartner::register(
            Id::fromString('01928a3c-0000-7000-8000-000000000101'),
            BusinessCode::of('CUS-MONALISA'),
            Name::of('Bakso Monalisa'),
            PartyType::ORGANIZATION,
            customerRole: CustomerRole::start($type, $terms),
            phone: PhoneNumber::of('0812-1111-2222'),
        );
    }

    private function supplier(): BusinessPartner
    {
        return BusinessPartner::register(
            Id::fromString('01928a3c-0000-7000-8000-000000000102'),
            BusinessCode::of('SUP-PAK-ANDI'),
            Name::of('Peternak Pak Andi'),
            PartyType::INDIVIDUAL,
            supplierRole: SupplierRole::start(PaymentTerms::cash(), 'ambil pagi'),
        );
    }

    public function test_partner_requires_at_least_one_role(): void
    {
        $this->expectExceptionMessage('at least one role');
        BusinessPartner::register(Id::fromString('01928a3c-0000-7000-8000-000000000103'), BusinessCode::of('X'), Name::of('X'), PartyType::INDIVIDUAL);
    }

    public function test_simple_cash_customer_needs_only_a_type(): void
    {
        $c = $this->customer(CustomerType::RETAIL);

        $this->assertTrue($c->isActiveCustomer());
        $this->assertFalse($c->isActiveSupplier());
        $this->assertTrue($c->customerRole()?->terms()->isCash());
        $this->assertNull($c->customerRole()?->terms()->creditLimit);
        $this->assertNull($c->email());
        $this->assertNull($c->address());
    }

    public function test_one_partner_can_be_supplier_and_customer(): void
    {
        $p = $this->supplier();
        $p->becomeCustomer(CustomerRole::start(CustomerType::RESELLER));

        $this->assertTrue($p->isActiveSupplier());
        $this->assertTrue($p->isActiveCustomer());
    }

    public function test_role_cannot_be_assigned_twice(): void
    {
        $p = $this->supplier();

        $this->expectExceptionMessage('already a supplier');
        $p->becomeSupplier(SupplierRole::start(PaymentTerms::cash()));
    }

    public function test_change_credit_terms(): void
    {
        $c = $this->customer();

        $this->assertTrue($c->changeCreditTerms(PaymentTerms::of(7, Money::of('1500000'))));
        $this->assertSame(7, $c->customerRole()?->terms()->termDays);
        $this->assertSame('1500000.0000', $c->customerRole()?->terms()->creditLimit?->toStorage());
        $this->assertFalse($c->changeCreditTerms(PaymentTerms::of(7, Money::of('1500000'))), 'same terms → no change');
    }

    public function test_terms_validation(): void
    {
        foreach ([fn () => PaymentTerms::of(-1), fn () => PaymentTerms::of(366), fn () => PaymentTerms::of(7, Money::of('-1'))] as $bad) {
            try {
                $bad();
                $this->fail('invalid terms accepted');
            } catch (InvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_walk_in_customer_is_cash_only(): void
    {
        try {
            $this->customer(CustomerType::WALK_IN, PaymentTerms::of(7));
            $this->fail();
        } catch (InvariantViolation $e) {
            $this->assertSame('WALK_IN_MUST_BE_CASH', $e->errorCode());
        }

        $walkIn = $this->customer(CustomerType::WALK_IN);
        $this->expectExceptionMessage('cash only');
        $walkIn->changeCreditTerms(PaymentTerms::of(3));
    }

    public function test_cannot_reclassify_tempo_customer_as_walk_in(): void
    {
        $c = $this->customer(CustomerType::RESTAURANT, PaymentTerms::of(7));

        $this->expectExceptionMessage('cash only');
        $c->reclassifyCustomer(CustomerType::WALK_IN);
    }

    public function test_supplier_has_no_credit_limit(): void
    {
        $this->expectExceptionMessage('credit limit applies to customers');
        SupplierRole::start(PaymentTerms::of(3, Money::of('1000')));
    }

    public function test_role_operations_on_missing_role_fail(): void
    {
        $this->expectExceptionMessage('is not a customer');
        $this->supplier()->changeCreditTerms(PaymentTerms::cash());
    }

    public function test_deactivating_role_or_partner_affects_usability_not_history(): void
    {
        $p = $this->supplier();
        $p->becomeCustomer(CustomerRole::start(CustomerType::RESELLER));

        $this->assertTrue($p->deactivateRole(PartnerRole::SUPPLIER));
        $this->assertFalse($p->isActiveSupplier());
        $this->assertTrue($p->hasRole(PartnerRole::SUPPLIER), 'role kept for history');
        $this->assertTrue($p->isActiveCustomer());

        $this->assertTrue($p->deactivate());
        $this->assertFalse($p->isActiveCustomer(), 'inactive partner is not usable in any role');

        $p->activate();
        $p->activateRole(PartnerRole::SUPPLIER);
        $this->assertTrue($p->isActiveSupplier());
    }

    public function test_contact_and_address_changes_report_changes(): void
    {
        $c = $this->customer();

        $this->assertFalse($c->changeContact(PhoneNumber::of('+6281211112222'), null), 'same phone in another format');
        $this->assertTrue($c->changeContact(PhoneNumber::of('0813-0000-0000'), null));
        $this->assertTrue($c->relocate(Address::of('Jl. Raya Cikarang 1')));
        $this->assertFalse($c->relocate(Address::of('Jl. Raya Cikarang 1')));
        $this->assertTrue($c->rename(Name::of('Bakso Monalisa Cikarang')));
        $this->assertTrue($c->changeLegalName(Name::of('CV Monalisa Sejahtera')));
    }

    public function test_snapshot_contains_roles(): void
    {
        $snap = $this->supplier()->snapshot();

        $this->assertSame('SUP-PAK-ANDI', $snap['code']);
        $this->assertSame(0, $snap['supplier']['payment_term_days']);
        $this->assertNull($snap['customer']);
    }
}
