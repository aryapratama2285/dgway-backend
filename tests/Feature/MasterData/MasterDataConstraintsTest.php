<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

/**
 * Proves the PostgreSQL constraints themselves (bypassing the application),
 * so the guarantees hold even for a buggy client, a manual SQL fix or a
 * future service writing to the same database.
 */
final class MasterDataConstraintsTest extends DatabaseTestCase
{
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoryId = $this->insert('product_categories', ['code' => 'EGG', 'name' => 'Telur']);
    }

    // ── catalog ──

    public function test_sku_unique(): void
    {
        $this->product(['sku' => 'TLR-A']);
        $this->assertViolates('products_sku_unique', fn () => $this->product(['sku' => 'TLR-A']));
    }

    public function test_sku_format_and_uppercase(): void
    {
        $this->assertViolates('products_sku_format_check', fn () => $this->product(['sku' => 'tlr-a']));
        $this->assertViolates('products_sku_format_check', fn () => $this->product(['sku' => 'TLR A']));
    }

    public function test_product_base_uom_must_exist(): void
    {
        $this->assertViolates('products_base_uom_code_foreign', fn () => $this->product(['base_uom_code' => 'TON']));
    }

    public function test_category_cannot_be_own_parent(): void
    {
        $this->assertViolates('product_categories_not_own_parent_check', fn () => DB::table('product_categories')->where('id', $this->categoryId)->update(['parent_id' => $this->categoryId]));
    }

    public function test_referenced_category_cannot_be_deleted(): void
    {
        $this->product();
        // Since A5 the identity guard rejects every delete first; the RESTRICT FK stays as second line of defence.
        $this->assertRestrictForeignKey('products_category_id_foreign');
        $this->assertViolates('holds stable identities', fn () => DB::table('product_categories')->where('id', $this->categoryId)->delete());
    }

    public function test_version_must_be_positive(): void
    {
        $this->assertViolates('products_version_check', fn () => $this->product(['version' => 0]));
    }

    // ── partners ──

    public function test_partner_role_rules(): void
    {
        $p = $this->partner();

        $this->assertViolates('bpr_role_check', fn () => $this->role($p, ['role' => 'CARRIER']));
        $this->assertViolates('bpr_customer_type_check', fn () => $this->role($p, ['role' => 'SUPPLIER', 'customer_type' => 'RETAIL']));
        $this->assertViolates('bpr_customer_type_check', fn () => $this->role($p, ['role' => 'CUSTOMER', 'customer_type' => null]));
        $this->assertViolates('bpr_supplier_no_credit_check', fn () => $this->role($p, ['role' => 'SUPPLIER', 'credit_limit' => 100]));
        $this->assertViolates('bpr_walk_in_cash_check', fn () => $this->role($p, ['role' => 'CUSTOMER', 'customer_type' => 'WALK_IN', 'payment_term_days' => 7]));
        $this->assertViolates('bpr_term_days_check', fn () => $this->role($p, ['role' => 'CUSTOMER', 'customer_type' => 'RETAIL', 'payment_term_days' => 400]));
        $this->assertViolates('bpr_credit_limit_check', fn () => $this->role($p, ['role' => 'CUSTOMER', 'customer_type' => 'RETAIL', 'credit_limit' => -1]));
    }

    public function test_one_row_per_partner_role(): void
    {
        $p = $this->partner();
        $this->role($p, ['role' => 'SUPPLIER']);
        $this->assertViolates('business_partner_roles_partner_id_role_unique', fn () => $this->role($p, ['role' => 'SUPPLIER']));
    }

    public function test_partner_contact_and_address_formats(): void
    {
        $this->assertViolates('business_partners_phone_e164_check', fn () => $this->partner(['phone' => '0812345678']));
        $this->assertViolates('business_partners_email_lowercase_check', fn () => $this->partner(['email' => 'A@B.ID']));
        $this->assertViolates('business_partners_address_postal_code_check', fn () => $this->partner(['address_street' => 'Jl X', 'address_postal_code' => '1234']));
        $this->assertViolates('business_partners_address_street_required_check', fn () => $this->partner(['address_city' => 'Bekasi']));
        $this->assertViolates('business_partners_address_coordinates_check', fn () => $this->partner(['address_street' => 'Jl X', 'address_latitude' => -6.2]));
        $this->assertViolates('business_partners_party_type_check', fn () => $this->partner(['party_type' => 'ROBOT']));
    }

    public function test_partner_with_roles_cannot_be_deleted(): void
    {
        $p = $this->partner();
        $this->role($p, ['role' => 'SUPPLIER']);
        // Since A5 the identity guard rejects every delete first; the RESTRICT FK stays as second line of defence.
        $this->assertRestrictForeignKey('business_partner_roles_partner_id_foreign');
        $this->assertViolates('holds stable identities', fn () => DB::table('business_partners')->where('id', $p)->delete());
    }

    // ── network ──

    public function test_node_capabilities_constraints(): void
    {
        $this->assertViolates('nodes_capabilities_check', fn () => $this->node(['capabilities' => '{}']));
        $this->assertViolates('nodes_capabilities_check', fn () => $this->node(['capabilities' => '{TELEPORT}']));
    }

    public function test_node_code_unique_and_location_fk(): void
    {
        $this->node(['code' => 'N1']);
        $this->assertViolates('nodes_code_unique', fn () => $this->node(['code' => 'N1']));
        $this->assertViolates('nodes_location_id_foreign', fn () => $this->node(['code' => 'N2', 'location_id' => (string) Str::uuid7()]));
    }

    public function test_location_with_nodes_cannot_be_deleted(): void
    {
        $loc = $this->insert('locations', ['code' => 'L1', 'name' => 'L1']);
        $this->node(['code' => 'N1', 'location_id' => $loc]);
        // Since A5 the identity guard rejects every delete first; the RESTRICT FK stays as second line of defence.
        $this->assertRestrictForeignKey('nodes_location_id_foreign');
        $this->assertViolates('holds stable identities', fn () => DB::table('locations')->where('id', $loc)->delete());
    }

    // ── helpers ──

    private function assertViolates(string $constraint, callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail("Expected constraint {$constraint} to reject the write");
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());
        }
    }

    private function insert(string $table, array $row): string
    {
        $id = (string) Str::uuid7();
        DB::table($table)->insert($row + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function product(array $row = []): string
    {
        return $this->insert('products', $row + ['sku' => 'SKU-'.Str::upper(Str::random(6)), 'name' => 'P', 'category_id' => $this->categoryId, 'base_uom_code' => 'KG']);
    }

    private function partner(array $row = []): string
    {
        return $this->insert('business_partners', $row + ['code' => 'BP-'.Str::upper(Str::random(6)), 'name' => 'BP', 'party_type' => 'INDIVIDUAL']);
    }

    private function role(string $partnerId, array $row): string
    {
        return $this->insert('business_partner_roles', $row + ['partner_id' => $partnerId]);
    }

    private function node(array $row = []): string
    {
        return $this->insert('nodes', $row + ['code' => 'N-'.Str::upper(Str::random(6)), 'name' => 'N', 'capabilities' => '{INVENTORY}']);
    }

    private function assertRestrictForeignKey(string $name): void
    {
        $this->assertSame('r', DB::table('pg_constraint')->where('conname', $name)->value('confdeltype'), "{$name} must be ON DELETE RESTRICT");
    }
}
