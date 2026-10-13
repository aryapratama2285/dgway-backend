<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

final class BusinessPartnerApiTest extends MasterDataTestCase
{
    private function createCustomer(array $override = []): string
    {
        return $this->cmd('POST', 'business-partners', array_replace([
            'code' => 'CUS-MONALISA',
            'name' => 'Bakso Monalisa',
            'party_type' => 'ORGANIZATION',
            'phone' => '0812-1111-2222',
            'customer' => ['customer_type' => 'RESTAURANT'],
        ], $override))->assertCreated()->json('data.id');
    }

    public function test_simple_cash_customer_with_minimal_data(): void
    {
        $res = $this->cmd('POST', 'business-partners', [
            'code' => 'cus-warung-bu-sri', 'name' => 'Warung Bu Sri', 'party_type' => 'INDIVIDUAL',
            'customer' => ['customer_type' => 'UMKM'],
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.code', 'CUS-WARUNG-BU-SRI')
            ->assertJsonPath('data.roles', ['CUSTOMER'])
            ->assertJsonPath('data.customer.is_cash', true)
            ->assertJsonPath('data.customer.term_days', 0)
            ->assertJsonPath('data.customer.credit_limit', null)
            ->assertJsonPath('data.supplier', null)
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.address', null);
    }

    public function test_full_partner_who_is_both_supplier_and_customer(): void
    {
        $res = $this->cmd('POST', 'business-partners', [
            'code' => 'BP-PENGEPUL-ANDI',
            'name' => 'Pengepul Pak Andi',
            'legal_name' => 'UD Andi Jaya',
            'party_type' => 'ORGANIZATION',
            'phone' => '+62 813-5555-0000',
            'email' => 'Andi@Example.ID',
            'address' => ['street' => 'Jl. Raya Serang 10', 'district' => 'Cikarang Barat', 'city' => 'Kabupaten Bekasi', 'province' => 'Jawa Barat', 'postal_code' => '17530', 'latitude' => -6.29, 'longitude' => 107.1],
            'supplier' => ['term_days' => 0, 'notes' => 'ambil jam 5 pagi'],
            'customer' => ['customer_type' => 'RESELLER', 'term_days' => 7, 'credit_limit' => '2000000'],
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.roles', ['SUPPLIER', 'CUSTOMER'])
            ->assertJsonPath('data.phone', '+6281355550000')
            ->assertJsonPath('data.phone_local', '081355550000')
            ->assertJsonPath('data.email', 'andi@example.id')
            ->assertJsonPath('data.address.postal_code', '17530')
            ->assertJsonPath('data.address.latitude', '-6.2900000')
            ->assertJsonPath('data.supplier.usable', true)
            ->assertJsonPath('data.customer.credit_limit', '2000000.0000');

        $this->assertSame(2, DB::table('business_partner_roles')->count());
        $this->assertSame(1, DB::table('business_partners')->count(), 'one identity, two roles — no duplicated master');
    }

    public function test_partner_needs_a_role(): void
    {
        $this->cmd('POST', 'business-partners', ['code' => 'X', 'name' => 'X', 'party_type' => 'INDIVIDUAL'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_walk_in_customer_cannot_get_tempo(): void
    {
        $this->cmd('POST', 'business-partners', [
            'code' => 'WALKIN', 'name' => 'Pembeli Umum', 'party_type' => 'INDIVIDUAL',
            'customer' => ['customer_type' => 'WALK_IN', 'term_days' => 7],
        ])->assertStatus(422)->assertJsonPath('error.code', 'WALK_IN_MUST_BE_CASH');
        $this->assertSame(0, DB::table('business_partners')->count(), 'rolled back completely');
    }

    public function test_supplier_cannot_have_credit_limit(): void
    {
        $this->cmd('POST', 'business-partners', [
            'code' => 'SUP-X', 'name' => 'X', 'party_type' => 'INDIVIDUAL', 'supplier' => ['credit_limit' => '100'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_CREDIT_LIMIT_NOT_APPLICABLE');
    }

    public function test_invalid_phone_and_postal_code(): void
    {
        $this->cmd('POST', 'business-partners', ['code' => 'A1', 'name' => 'A', 'party_type' => 'INDIVIDUAL', 'phone' => 'abc', 'customer' => ['customer_type' => 'RETAIL']])
            ->assertStatus(422)->assertJsonPath('error.code', 'PHONE_INVALID');
        $this->cmd('POST', 'business-partners', ['code' => 'A2', 'name' => 'A', 'party_type' => 'INDIVIDUAL', 'address' => ['street' => 'Jl X', 'postal_code' => '123'], 'customer' => ['customer_type' => 'RETAIL']])
            ->assertStatus(422)->assertJsonPath('error.code', 'POSTAL_CODE_INVALID');
    }

    public function test_duplicate_partner_code_is_409_and_retry_is_replayed(): void
    {
        $key = '5a1e0000-aaaa-4bbb-8ccc-000000000002';
        $body = ['code' => 'CUS-1', 'name' => 'C1', 'party_type' => 'INDIVIDUAL', 'customer' => ['customer_type' => 'RETAIL']];

        $first = $this->cmd('POST', 'business-partners', $body, $key)->assertCreated();
        $this->cmd('POST', 'business-partners', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->cmd('POST', 'business-partners', $body)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICATE_CODE');

        $this->assertSame(1, DB::table('business_partners')->count());
    }

    public function test_change_credit_terms_and_reclassify(): void
    {
        $id = $this->createCustomer();

        $this->cmd('PATCH', "business-partners/{$id}/roles/customer", ['term_days' => 7, 'credit_limit' => '1500000'])
            ->assertOk()
            ->assertJsonPath('data.customer.term_days', 7)
            ->assertJsonPath('data.customer.credit_limit', '1500000.0000')
            ->assertJsonPath('data.customer.is_cash', false);

        // changing only the limit keeps the tempo
        $this->cmd('PATCH', "business-partners/{$id}/roles/customer", ['credit_limit' => null])
            ->assertOk()->assertJsonPath('data.customer.term_days', 7)->assertJsonPath('data.customer.credit_limit', null);

        $this->cmd('PATCH', "business-partners/{$id}/roles/customer", ['customer_type' => 'CATERING'])
            ->assertOk()->assertJsonPath('data.customer.customer_type', 'CATERING');

        $this->assertSame(3, DB::table('audit_logs')->where('action', 'business_partner.role_updated')->count());
    }

    public function test_assign_supplier_role_to_existing_customer(): void
    {
        $id = $this->createCustomer();

        $this->cmd('POST', "business-partners/{$id}/roles", ['role' => 'SUPPLIER', 'term_days' => 3])
            ->assertOk()->assertJsonPath('data.roles', ['SUPPLIER', 'CUSTOMER'])->assertJsonPath('data.supplier.term_days', 3);

        $this->cmd('POST', "business-partners/{$id}/roles", ['role' => 'SUPPLIER'])
            ->assertStatus(422)->assertJsonPath('error.code', 'ROLE_ALREADY_ASSIGNED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_partner.role_assigned', 'entity_id' => $id]);
    }

    public function test_role_deactivation_is_independent_of_partner(): void
    {
        $id = $this->createCustomer(['supplier' => ['term_days' => 0]]);

        $this->cmd('POST', "business-partners/{$id}/roles/supplier/deactivate")->assertOk()
            ->assertJsonPath('data.supplier.usable', false)
            ->assertJsonPath('data.customer.usable', true);

        $this->assertSame(0, $this->get_('business-partners?role=SUPPLIER&status=active')->json('meta.total'));
        $this->assertSame(1, $this->get_('business-partners?role=CUSTOMER&status=active')->json('meta.total'));
        $this->assertSame(1, $this->get_('business-partners?role=SUPPLIER&status=all')->json('meta.total'));
        $this->assertSame(1, $this->get_('business-partners?role=SUPPLIER&status=inactive')->json('meta.total'));
        $this->assertSame(0, $this->get_('business-partners?role=CUSTOMER&status=inactive')->json('meta.total'));
    }

    public function test_update_contact_and_address_and_deactivate_partner(): void
    {
        $id = $this->createCustomer();

        $this->cmd('PATCH', "business-partners/{$id}", ['phone' => '0813 9999 8888', 'address' => ['street' => 'Jl. Kenari 12', 'city' => 'Bekasi']])
            ->assertOk()->assertJsonPath('data.phone', '+6281399998888')->assertJsonPath('data.address.city', 'Bekasi');

        $this->cmd('PATCH', "business-partners/{$id}", ['address' => null])->assertOk()->assertJsonPath('data.address', null);
        $this->cmd('PATCH', "business-partners/{$id}", ['code' => 'NEW'])->assertStatus(422);

        $this->cmd('POST', "business-partners/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.customer.usable', false);
        $this->assertSame(1, $this->get_('business-partners?role=CUSTOMER&status=inactive')->json('meta.total'));
        $this->get_("business-partners/{$id}")->assertOk()->assertJsonPath('data.name', 'Bakso Monalisa');

        $actions = DB::table('audit_logs')->where('entity_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['business_partner.created', 'business_partner.updated', 'business_partner.updated', 'business_partner.deactivated'], $actions);
    }

    public function test_search_by_phone_regardless_of_format(): void
    {
        $this->createCustomer();
        $this->createCustomer(['code' => 'CUS-OTHER', 'name' => 'Lainnya', 'phone' => '0857-0000-1111']);

        $this->assertSame(['CUS-MONALISA'], array_column($this->get_('business-partners?q=081211112222')->json('data'), 'code'));
        $this->assertSame(['CUS-MONALISA'], array_column($this->get_('business-partners?q=%2B62 812-1111-2222')->json('data'), 'code'));
        $this->assertSame(['CUS-MONALISA'], array_column($this->get_('business-partners?q=monalisa')->json('data'), 'code'));
    }

    public function test_filter_by_customer_type(): void
    {
        $this->createCustomer();
        $this->createCustomer(['code' => 'CUS-CAT', 'name' => 'Catering Ibu', 'customer' => ['customer_type' => 'CATERING']]);

        $this->assertSame(['CUS-CAT'], array_column($this->get_('business-partners?customer_type=CATERING')->json('data'), 'code'));
    }
}
