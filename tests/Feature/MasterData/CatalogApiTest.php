<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Support\Facades\DB;

final class CatalogApiTest extends MasterDataTestCase
{
    public function test_category_hierarchy_food_egg_and_packaging(): void
    {
        $food = $this->category('FOOD');
        $egg = $this->category('EGG', $food);
        $packaging = $this->category('PACKAGING');
        $this->category('TRASH_BAG', $packaging);

        $this->get_("categories/{$egg}")->assertOk()
            ->assertJsonPath('data.code', 'EGG')
            ->assertJsonPath('data.parent_id', $food)
            ->assertJsonPath('data.version', 1);
        $this->assertCount(4, $this->get_('categories')->json('data'));
    }

    public function test_create_product_and_read_it_back(): void
    {
        $cat = $this->category('EGG');

        $res = $this->cmd('POST', 'products', [
            'sku' => 'tlr-a', 'name' => '  Telur Ayam   Grade A ', 'category_id' => $cat, 'base_uom' => 'kg', 'description' => 'Berat 60–65 g',
        ]);

        $res->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.sku', 'TLR-A')
            ->assertJsonPath('data.name', 'Telur Ayam Grade A')
            ->assertJsonPath('data.base_uom.code', 'KG')
            ->assertJsonPath('data.base_uom.precision', 3)
            ->assertJsonPath('data.is_active', true);

        $this->get_('products/'.$res->json('data.id'))->assertOk()->assertJsonPath('data.description', 'Berat 60–65 g');
    }

    public function test_non_egg_products_work_the_same_way(): void
    {
        $bag = $this->product('TB-60X100', $this->category('TRASH_BAG'), 'PCS');
        $onion = $this->product('BWM-1IKAT', $this->category('VEGETABLE'), 'IKAT');

        $this->get_("products/{$bag}")->assertJsonPath('data.base_uom.code', 'PCS');
        $this->get_("products/{$onion}")->assertJsonPath('data.base_uom.code', 'IKAT');
    }

    public function test_retry_with_same_key_does_not_create_a_duplicate_product(): void
    {
        $cat = $this->category();
        $key = '5a1e0000-aaaa-4bbb-8ccc-000000000001';
        $body = ['sku' => 'TLR-A', 'name' => 'Telur A', 'category_id' => $cat, 'base_uom' => 'KG'];

        $first = $this->cmd('POST', 'products', $body, $key)->assertCreated();
        $retry = $this->cmd('POST', 'products', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'product.created')->count());
    }

    public function test_duplicate_sku_with_new_key_is_a_clean_409(): void
    {
        $cat = $this->category();
        $this->product('TLR-A', $cat);

        $this->cmd('POST', 'products', ['sku' => 'tlr-a', 'name' => 'Lain', 'category_id' => $cat, 'base_uom' => 'KG'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_CODE')
            ->assertJsonPath('error.details.code', 'TLR-A');
        $this->assertSame(1, DB::table('products')->count());
    }

    public function test_invalid_inputs_are_rejected_with_domain_codes(): void
    {
        $cat = $this->category();

        $this->cmd('POST', 'products', ['sku' => 'TLR A', 'name' => 'x', 'category_id' => $cat, 'base_uom' => 'KG'])
            ->assertStatus(422)->assertJsonPath('error.code', 'CODE_INVALID');
        $this->cmd('POST', 'products', ['sku' => 'TLR-B', 'name' => 'x', 'category_id' => $cat, 'base_uom' => 'TON'])
            ->assertStatus(422)->assertJsonPath('error.code', 'UOM_UNKNOWN');
        $this->cmd('POST', 'products', ['sku' => 'TLR-C', 'name' => 'x', 'category_id' => '01928a3c-0000-7000-8000-00000000ffff', 'base_uom' => 'KG'])
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->cmd('POST', 'products', ['sku' => 'TLR-D'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_update_uses_intent_methods_and_audits_only_changed_fields(): void
    {
        $id = $this->product('TLR-A');
        $protein = $this->category('PROTEIN');

        $res = $this->cmd('PATCH', "products/{$id}", ['name' => 'Telur Ayam Negeri A', 'category_id' => $protein, 'expected_version' => 1]);

        $res->assertOk()->assertJsonPath('data.name', 'Telur Ayam Negeri A')->assertJsonPath('data.version', 2);
        $audit = DB::table('audit_logs')->where('action', 'product.updated')->first();
        $this->assertSame($this->owner->id, $audit->actor_user_id);
        $this->assertEqualsCanonicalizing(['name', 'category_id'], array_keys(json_decode($audit->after, true)));
        $this->assertSame('Produk TLR-A', json_decode($audit->before, true)['name']);
    }

    public function test_no_op_update_does_not_bump_version_or_audit(): void
    {
        $id = $this->product('TLR-A');

        $this->cmd('PATCH', "products/{$id}", ['name' => 'Produk TLR-A'])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'product.updated')->count());
    }

    public function test_sku_is_immutable(): void
    {
        $id = $this->product('TLR-A');

        $this->cmd('PATCH', "products/{$id}", ['sku' => 'TLR-Z'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->get_("products/{$id}")->assertJsonPath('data.sku', 'TLR-A');
    }

    public function test_stale_expected_version_is_rejected(): void
    {
        $id = $this->product('TLR-A');
        $this->cmd('PATCH', "products/{$id}", ['name' => 'Nama Baru'])->assertOk(); // now version 2

        $this->cmd('PATCH', "products/{$id}", ['name' => 'Edit dari HP lain', 'expected_version' => 1])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION')
            ->assertJsonPath('error.details.actual_version', 2);
        $this->get_("products/{$id}")->assertJsonPath('data.name', 'Nama Baru');
    }

    public function test_base_uom_can_change_before_any_transaction(): void
    {
        $id = $this->product('TLR-A');

        $this->cmd('PATCH', "products/{$id}", ['base_uom' => 'PCS'])->assertOk()->assertJsonPath('data.base_uom.code', 'PCS');
    }

    public function test_deactivate_and_activate_keep_record_retrievable(): void
    {
        $id = $this->product('TLR-A');

        $this->cmd('POST', "products/{$id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->get_("products/{$id}")->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $this->get_('products?status=active')->json('meta.total'));
        $this->assertSame(1, $this->get_('products?status=inactive')->json('meta.total'));
        $this->assertSame(1, $this->get_('products')->json('meta.total'), 'default list shows all');

        $this->cmd('POST', "products/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        $actions = DB::table('audit_logs')->where('entity_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['product.created', 'product.deactivated', 'product.activated'], $actions);
    }

    public function test_category_with_active_products_cannot_be_deactivated(): void
    {
        $cat = $this->category('EGG');
        $product = $this->product('TLR-A', $cat);

        $this->cmd('POST', "categories/{$cat}/deactivate")->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_IN_USE');

        $this->cmd('POST', "products/{$product}/deactivate")->assertOk();
        $this->cmd('POST', "categories/{$cat}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        // inactive product in inactive category stays readable (history)
        $this->get_("products/{$product}")->assertOk()->assertJsonPath('data.category_id', $cat);
        // but cannot be re-activated until its category is active
        $this->cmd('POST', "products/{$product}/activate")->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_INACTIVE');
        // and no new product may be created in it
        $this->cmd('POST', 'products', ['sku' => 'TLR-B', 'name' => 'B', 'category_id' => $cat, 'base_uom' => 'KG'])
            ->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_INACTIVE');
    }

    public function test_category_cycle_is_rejected_via_api(): void
    {
        $food = $this->category('FOOD');
        $egg = $this->category('EGG', $food);

        $this->cmd('PATCH', "categories/{$food}", ['parent_id' => $egg])->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_CYCLE');
        $this->cmd('PATCH', "categories/{$food}", ['parent_id' => $food])->assertStatus(422)->assertJsonPath('error.code', 'CATEGORY_CYCLE');
    }

    public function test_product_list_search_filter_and_pagination(): void
    {
        $egg = $this->category('EGG');
        $veg = $this->category('VEGETABLE');
        $this->product('TLR-A', $egg);
        $this->product('TLR-B', $egg);
        $this->product('BWM-1', $veg, 'IKAT');

        $this->assertSame(2, $this->get_("products?category_id={$egg}")->json('meta.total'));
        $this->assertSame(['BWM-1'], array_column($this->get_('products?q=bwm')->json('data'), 'sku'));
        $this->assertSame(3, $this->get_('products?q=produk')->json('meta.total'), 'search by name');

        $page = $this->get_('products?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $page->json('meta'));
    }

    public function test_search_input_is_not_interpreted_as_sql_or_wildcards(): void
    {
        $this->product('TLR-A');

        $this->get_("products?q=%25")->assertOk()->assertJsonPath('meta.total', 0);
        $this->get_("products?q=' OR 1=1 --")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_uom_reference_list(): void
    {
        $this->assertSame(['IKAT', 'KG', 'PCS'], array_column($this->get_('uoms')->json('data'), 'code'));
    }

    public function test_mutation_without_idempotency_key_is_refused(): void
    {
        $this->postJson('/api/v1/categories', ['code' => 'X', 'name' => 'X'])->assertStatus(428);
        $this->assertSame(0, DB::table('product_categories')->count());
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer invalid'])->getJson('/api/v1/products')->assertStatus(401);
    }
}
