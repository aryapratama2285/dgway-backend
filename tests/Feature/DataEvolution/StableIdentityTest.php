<?php

declare(strict_types=1);

namespace Tests\Feature\DataEvolution;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MasterData\MasterDataTestCase;

/**
 * A5 addendum §1 — stable identity. Master records are referenced by every
 * later generation of the system (Project B), so their UUID and business key
 * must never change and the row must never disappear — enforced by the
 * database, not only by the absence of an API endpoint.
 */
final class StableIdentityTest extends MasterDataTestCase
{
    private function assertGuarded(Closure $sql): void
    {
        try {
            DB::transaction($sql);
            $this->fail('The database accepted a change to a stable identity.');
        } catch (QueryException $e) {
            $this->assertSame('23001', $e->errorInfo[0] ?? null, $e->getMessage());
        }
    }

    /** @return array<string, string> table => id of a fresh, unreferenced row */
    private function freshMasterRows(): array
    {
        $cat = $this->category('UNUSED-CAT');
        $prod = $this->product('UNUSED-SKU', $this->category('OTHER-CAT'));
        $bp = $this->cmd('POST', 'business-partners', ['code' => 'BP-UNUSED', 'name' => 'Unused', 'party_type' => 'INDIVIDUAL', 'customer' => ['customer_type' => 'RETAIL']])->json('data.id');
        $loc = $this->cmd('POST', 'locations', ['code' => 'LOC-UNUSED', 'name' => 'Unused'])->json('data.id');
        $node = $this->cmd('POST', 'nodes', ['code' => 'NODE-UNUSED', 'name' => 'Unused', 'capabilities' => ['PICKUP']])->json('data.id');

        return ['product_categories' => $cat, 'products' => $prod, 'business_partners' => $bp, 'locations' => $loc, 'nodes' => $node];
    }

    public function test_master_rows_cannot_be_hard_deleted_even_when_unreferenced(): void
    {
        foreach ($this->freshMasterRows() as $table => $id) {
            $this->assertGuarded(fn () => DB::table($table)->where('id', $id)->delete());
        }
        $this->assertGuarded(fn () => DB::table('business_partner_roles')->delete());
        $this->assertGuarded(fn () => DB::table('uoms')->where('code', 'IKAT')->delete());
        $this->assertGuarded(fn () => DB::table('users')->where('id', $this->owner->id)->delete());
    }

    public function test_uuid_and_business_key_are_immutable(): void
    {
        $rows = $this->freshMasterRows();
        $newId = '01928a3c-0000-7000-8000-00000000abcd';
        foreach ($rows as $table => $id) {
            $this->assertGuarded(fn () => DB::table($table)->where('id', $id)->update(['id' => $newId]));
        }
        $this->assertGuarded(fn () => DB::table('products')->where('id', $rows['products'])->update(['sku' => 'RENAMED-SKU']));
        $this->assertGuarded(fn () => DB::table('product_categories')->where('id', $rows['product_categories'])->update(['code' => 'RENAMED']));
        $this->assertGuarded(fn () => DB::table('business_partners')->where('id', $rows['business_partners'])->update(['code' => 'RENAMED']));
        $this->assertGuarded(fn () => DB::table('locations')->where('id', $rows['locations'])->update(['code' => 'RENAMED']));
        $this->assertGuarded(fn () => DB::table('nodes')->where('id', $rows['nodes'])->update(['code' => 'RENAMED']));
        $this->assertGuarded(fn () => DB::table('uoms')->where('code', 'KG')->update(['code' => 'KILO']));
        $this->assertGuarded(fn () => DB::table('business_partner_roles')->where('partner_id', $rows['business_partners'])->update(['role' => 'SUPPLIER', 'customer_type' => null]));
    }

    public function test_ordinary_master_data_edits_still_work(): void
    {
        $rows = $this->freshMasterRows();
        $this->cmd('PATCH', "products/{$rows['products']}", ['name' => 'Nama baru'])->assertOk();
        $this->cmd('POST', "nodes/{$rows['nodes']}/deactivate")->assertOk();
        $this->cmd('PATCH', "business-partners/{$rows['business_partners']}/roles/customer", ['term_days' => 7])->assertOk();
        $this->assertSame('Nama baru', DB::table('products')->where('id', $rows['products'])->value('name'));
    }
}
