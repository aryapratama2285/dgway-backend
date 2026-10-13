<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Infrastructure\Persistence\Eloquent\User;
use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Sales\SalesTestCase;

/**
 * Phase F5A — operational hardening:
 * STAFF role boundaries, permissions in the auth payload, password change,
 * console operator administration, idempotency scope per operator, purchasing
 * units, proxy trust parsing, scheduler jobs and the production doctor.
 */
final class F5AHardeningTest extends SalesTestCase
{
    private function staff(string $email = 'staff@dgway.test'): User
    {
        return User::factory()->create(['name' => 'Staff Satu', 'email' => $email, 'role' => 'STAFF']);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['operate']);
    }

    // ── STAFF role ───────────────────────────────────────────────────────

    public function test_staff_role_is_accepted_by_the_database_and_owner_remains_default(): void
    {
        $staff = $this->staff();
        $this->assertSame('STAFF', DB::table('users')->where('id', $staff->id)->value('role'));
        $this->assertSame('OWNER', DB::table('users')->where('id', $this->owner->id)->value('role'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->where('id', $staff->id)->update(['role' => 'ADMIN']);
    }

    public function test_auth_me_lists_permissions_all_for_owner_none_for_staff(): void
    {
        $owner = $this->get_('auth/me')->assertOk()->assertJsonPath('data.role', 'OWNER')->json('data.permissions');
        $this->assertContains('sales.reverse', $owner);
        $this->assertContains('inventory.correct', $owner);
        $this->assertContains('partners.manage_credit', $owner);
        $this->assertContains('network.manage', $owner);

        $this->actAs($this->staff());
        $this->get_('auth/me')->assertOk()->assertJsonPath('data.role', 'STAFF')->assertJsonPath('data.permissions', []);
    }

    public function test_login_payload_carries_role_and_permissions(): void
    {
        $this->staff('kasir@dgway.test')->forceFill(['password' => 'rahasia-kasir-1'])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@dgway.test', 'password' => 'rahasia-kasir-1', 'device_name' => 'A32'])
            ->assertOk()
            ->assertJsonPath('data.operator.role', 'STAFF')
            ->assertJsonPath('data.operator.permissions', []);
    }

    public function test_staff_can_do_routine_daily_work(): void
    {
        $this->stockUp('50');
        $plastic = $this->cmd('POST', 'products', [
            'sku' => 'PLASTIK-15', 'name' => 'Plastik', 'category_id' => $this->category('PACK'), 'base_uom' => 'PCS', 'classification' => 'PACKAGING',
        ])->assertCreated()->json('data.id');
        $this->receive([$this->line('10', '150', [], $plastic)]);
        $this->actAs($this->staff());

        // master data without credit
        $cashCustomer = $this->cmd('POST', 'business-partners', [
            'code' => 'CUS-WARUNG', 'name' => 'Warung', 'party_type' => 'INDIVIDUAL', 'customer' => ['customer_type' => 'RESELLER', 'term_days' => 0],
        ])->assertCreated()->json('data.id');
        $this->cmd('POST', 'business-partners', ['code' => 'SUP-NEW', 'name' => 'Sup', 'party_type' => 'ORGANIZATION', 'supplier' => ['term_days' => 14]])->assertCreated();
        $this->cmd('POST', 'products', ['sku' => 'TRAY-KRT', 'name' => 'Tray karton', 'category_id' => DB::table('product_categories')->value('id'), 'base_uom' => 'PCS'])->assertCreated();

        // sale + payment
        $sale = $this->sell($cashCustomer, '2', '26000', true, [$this->cash('52000')])->assertCreated()->assertJsonPath('data.payment_status', 'PAID')->json('data');
        $this->assertSame('COMPLETED', $sale['status']);

        // purchasing and receiving
        $this->receive([$this->line('5')]);

        // internal consumption
        $this->cmd('POST', 'inventory/consumptions', ['node_id' => $this->node, 'product_id' => $plastic, 'quantity' => '1', 'reason_code' => 'PACKING'])->assertCreated();
        $this->assertLedgerConsistent();
    }

    public function test_staff_is_denied_every_sensitive_action(): void
    {
        $this->stockUp('50');
        $credit = $this->customer('CUS-CREDIT', 7, '1000000');
        $sale = $this->sell($credit, '2', '25000', true, [$this->cash('10000')])->assertCreated()->json('data');
        $paymentId = DB::table('payments')->where('sale_id', $sale['id'])->value('id');
        $grId = DB::table('goods_receipts')->value('id');

        $this->actAs($this->staff());
        $denied = [
            ['POST', "sales/{$sale['id']}/reverse", ['reason' => 'salah input', 'reverse_payments' => true], 'sales.reverse'],
            ['POST', "payments/{$paymentId}/reverse", ['reason' => 'salah'], 'payments.reverse'],
            ['POST', "goods-receipts/{$grId}/reverse", ['reason' => 'salah'], 'procurement.reverse'],
            ['POST', 'inventory/adjustments', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'direction' => 'OUT', 'reason' => 'x'], 'inventory.adjust'],
            ['POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'pecah'], 'inventory.correct'],
            ['POST', 'inventory/recoveries', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'from_bucket' => 'QUARANTINE', 'reason' => 'ok'], 'inventory.correct'],
            ['POST', 'inventory/supplier-returns', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'retur'], 'inventory.correct'],
            ['POST', 'business-partners', ['code' => 'CUS-TEMPO', 'name' => 'Tempo', 'party_type' => 'ORGANIZATION', 'customer' => ['customer_type' => 'CATERING', 'term_days' => 14]], 'partners.manage_credit'],
            ['POST', 'business-partners', ['code' => 'CUS-LIMIT', 'name' => 'Limit', 'party_type' => 'ORGANIZATION', 'customer' => ['customer_type' => 'CATERING', 'term_days' => 0, 'credit_limit' => '500000']], 'partners.manage_credit'],
            ['PATCH', "business-partners/{$credit}/roles/customer", ['credit_limit' => '9000000'], 'partners.manage_credit'],
            ['POST', 'nodes', ['code' => 'GUDANG-2', 'name' => 'Gudang 2', 'capabilities' => ['INVENTORY']], 'network.manage'],
            ['PATCH', "nodes/{$this->node}", ['name' => 'Ganti'], 'network.manage'],
            ['PATCH', 'print/settings', ['business_name' => 'X'], 'settings.manage_print'],
            ['PUT', "products/{$this->egg}/uom-conversions/TRAY", ['factor_to_base' => '1.8'], 'catalog.manage_uom_conversion'],
        ];
        foreach ($denied as [$method, $uri, $body, $permission]) {
            $this->cmd($method, $uri, $body)->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED')->assertJsonPath('error.details.permission', $permission);
        }

        // nothing changed
        $this->actAs($this->owner);
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertSame('48.0000', $this->stock()['available']);
        $this->assertSame(0, DB::table('business_partners')->whereIn('code', ['CUS-TEMPO', 'CUS-LIMIT'])->count());
        $this->assertLedgerConsistent();
    }

    public function test_staff_may_edit_a_customer_without_touching_credit_terms(): void
    {
        $credit = $this->customer('CUS-CREDIT', 7, '1000000');
        $this->actAs($this->staff());
        $this->cmd('PATCH', "business-partners/{$credit}/roles/customer", ['notes' => 'antar pagi'])->assertOk();
        // re-sending the SAME terms is not a change
        $this->cmd('PATCH', "business-partners/{$credit}/roles/customer", ['term_days' => 7, 'credit_limit' => '1000000'])->assertOk();
        $this->cmd('PATCH', "business-partners/{$credit}", ['phone' => '081299990000'])->assertOk();
    }

    public function test_owner_keeps_every_sensitive_action_and_bootstrap_still_works_as_system(): void
    {
        $this->stockUp('20');
        $this->cmd('POST', 'inventory/losses', ['node_id' => $this->node, 'product_id' => $this->egg, 'quantity' => '1', 'reason' => 'pecah'])->assertCreated();
        $this->customer('CUS-TEMPO', 14, '500000');
        $this->cmd('POST', 'nodes', ['code' => 'GUDANG-2', 'name' => 'Gudang 2', 'capabilities' => ['INVENTORY']])->assertCreated();

        // bootstrap runs as the system actor: network + walk-in customer are created without a human permission
        $this->assertSame(0, Artisan::call('dgway:bootstrap'));
        $this->assertTrue(DB::table('business_partners')->where('code', 'WALK-IN')->exists());
    }

    // ── password change ──────────────────────────────────────────────────

    public function test_password_change_rules_and_other_sessions_revoked(): void
    {
        $user = $this->staff('ganti@dgway.test');
        $user->forceFill(['password' => 'password-lama-1'])->save();
        $phone = $this->postJson('/api/v1/auth/login', ['email' => 'ganti@dgway.test', 'password' => 'password-lama-1', 'device_name' => 'phone'])->json('data.token');
        $tablet = $this->postJson('/api/v1/auth/login', ['email' => 'ganti@dgway.test', 'password' => 'password-lama-1', 'device_name' => 'tablet'])->json('data.token');
        $this->app['auth']->forgetGuards();

        $h = ['Authorization' => "Bearer {$phone}"];
        $this->postJson('/api/v1/auth/password', ['current_password' => 'salah-sekali', 'new_password' => 'password-baru-1'], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'CURRENT_PASSWORD_INVALID');
        $this->postJson('/api/v1/auth/password', ['current_password' => 'password-lama-1', 'new_password' => 'pendek'], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_TOO_SHORT');
        $this->postJson('/api/v1/auth/password', ['current_password' => 'password-lama-1', 'new_password' => 'password-lama-1'], $h)
            ->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_UNCHANGED');
        $this->postJson('/api/v1/auth/password', ['current_password' => 'password-lama-1', 'new_password' => 'password-baru-1'], $h)
            ->assertOk()->assertJsonPath('data.password_changed', true)->assertJsonPath('data.other_sessions_revoked', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $h)->assertOk(); // this device stays signed in
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$tablet}"])->assertStatus(401); // the other device is out
        $this->postJson('/api/v1/auth/login', ['email' => 'ganti@dgway.test', 'password' => 'password-lama-1', 'device_name' => 'x'])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'ganti@dgway.test', 'password' => 'password-baru-1', 'device_name' => 'x'])->assertOk();

        $audit = DB::table('audit_logs')->where('action', 'operator.password_changed')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('password-baru-1', (string) $audit->after);
        $this->assertSame(0, DB::table('idempotency_keys')->where('command', 'like', '%password%')->count(), 'password bodies never reach the idempotency store');
    }

    // ── console operator administration ──────────────────────────────────

    public function test_console_creates_staff_deactivates_and_resets_password(): void
    {
        $this->assertSame(1, Artisan::call('dgway:operator:create', ['email' => 'x@dgway.test', '--name' => 'X', '--role' => 'ADMIN']));

        $created = $this->app->make(\App\Application\Identity\RegisterOperator::class)
            ->execute(\App\Domain\Shared\Identity\Actor::system('console'), 'Kasir', 'kasir2@dgway.test', 'password-kasir-1', \App\Domain\Identity\Role::STAFF);
        $this->assertSame('STAFF', $created->role->value);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'kasir2@dgway.test', 'password' => 'password-kasir-1', 'device_name' => 'A32'])->json('data.token');
        $this->assertNotNull($token);

        $admin = $this->app->make(\App\Application\Identity\AdministerOperator::class);
        $system = \App\Domain\Shared\Identity\Actor::system('console');
        $this->assertSame(1, $admin->setActive($system, 'kasir2@dgway.test', false));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
        $this->assertSame(0, $admin->setActive($system, 'kasir2@dgway.test', true));

        $admin->resetPassword($system, 'kasir2@dgway.test', 'password-reset-1');
        $this->postJson('/api/v1/auth/login', ['email' => 'kasir2@dgway.test', 'password' => 'password-reset-1', 'device_name' => 'A32'])->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'operator.password_reset')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'operator.deactivated')->count());

        $this->assertSame(0, Artisan::call('dgway:operator:list'));
        $this->assertStringContainsString('kasir2@dgway.test', Artisan::output());
    }

    // ── idempotency scope ────────────────────────────────────────────────

    public function test_same_idempotency_key_from_two_operators_is_scoped_per_operator(): void
    {
        $key = (string) Str::uuid();
        $a = $this->cmd('POST', 'categories', ['code' => 'CAT-OWNER', 'name' => 'Owner'], $key)->assertCreated()->json('data.id');

        $this->actAs($this->staff());
        $b = $this->cmd('POST', 'categories', ['code' => 'CAT-STAFF', 'name' => 'Staff'], $key)->assertCreated()->json('data.id');
        $this->assertNotSame($a, $b, 'a different operator using the same key value is a different command');
        // and each operator still gets its own replay
        $this->cmd('POST', 'categories', ['code' => 'CAT-STAFF', 'name' => 'Staff'], $key)->assertCreated()->assertJsonPath('data.id', $b)->assertHeader('Idempotent-Replayed', 'true');

        $this->actAs($this->owner);
        $this->cmd('POST', 'categories', ['code' => 'CAT-OWNER', 'name' => 'Owner'], $key)->assertCreated()->assertJsonPath('data.id', $a);
        $this->assertSame(2, DB::table('idempotency_keys')->where('idempotency_key', $key)->count());
        $this->assertSame(2, DB::table('product_categories')->whereIn('code', ['CAT-OWNER', 'CAT-STAFF'])->count());
    }

    // ── purchasing units ─────────────────────────────────────────────────

    public function test_purchasing_units_exist_and_their_size_is_per_product(): void
    {
        $codes = array_column($this->get_('uoms')->assertOk()->json('data'), 'code');
        foreach (['KG', 'PCS', 'IKAT', 'TRAY', 'BOX', 'PACK', 'ROLL', 'LITER'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame('Dus/Box', DB::table('uoms')->where('code', 'BOX')->value('name'));
        $this->assertSame(3, (int) DB::table('uoms')->where('code', 'LITER')->value('precision'));

        // product-specific: the same TRAY is 1.8 KG for eggs and 30 PCS for another product
        $pcs = $this->product('TELUR-BUTIR', null, 'PCS');
        $this->cmd('PUT', "products/{$this->egg}/uom-conversions/TRAY", ['factor_to_base' => '1.8'])->assertOk();
        $this->cmd('PUT', "products/{$pcs}/uom-conversions/TRAY", ['factor_to_base' => '30'])->assertOk();
        $this->assertSame(2, DB::table('product_uom_conversions')->where('uom_code', 'TRAY')->count());
    }

    // ── production edge ──────────────────────────────────────────────────

    public function test_trusted_proxy_setting_is_parsed_and_empty_means_none(): void
    {
        config(['dgway.trusted_proxies' => '']);
        $this->assertNull(AppServiceProvider::trustedProxies());
        config(['dgway.trusted_proxies' => '*']);
        $this->assertSame('*', AppServiceProvider::trustedProxies());
        config(['dgway.trusted_proxies' => ' 172.16.0.0/12, 10.0.0.5 ,']);
        $this->assertSame(['172.16.0.0/12', '10.0.0.5'], AppServiceProvider::trustedProxies());
    }

    public function test_scheduler_runs_maintenance_jobs(): void
    {
        $events = array_map(fn ($e) => (string) ($e->command ?? $e->description), $this->app->make(Schedule::class)->events());
        $joined = implode("\n", $events);
        $this->assertStringContainsString('dgway:idempotency:prune', $joined);
        $this->assertStringContainsString('sanctum:prune-expired', $joined);
        $this->assertStringContainsString('dgway:inventory:verify', $joined);
        $this->assertStringContainsString('dgway:scheduler-heartbeat', $joined);
    }

    private function doctorChecks(): \Illuminate\Support\Collection
    {
        $out = Artisan::output();
        $json = json_decode(substr($out, (int) strpos($out, '{'), (int) strrpos($out, '}') - (int) strpos($out, '{') + 1), true);

        return collect($json['checks'])->keyBy('check');
    }

    public function test_doctor_production_rules_for_url_proxies_logs_and_scheduler(): void
    {
        config(['app.url' => 'http://api.example.test', 'dgway.trusted_proxies' => '', 'logging.default' => 'stack', 'logging.channels.stack.channels' => ['single']]);
        @unlink((string) config('dgway.scheduler_heartbeat_path'));
        Artisan::call('dgway:doctor', ['--production' => true, '--json' => true]);
        $checks = $this->doctorChecks();
        $this->assertSame('FAIL', $checks['APP_URL']['result']);
        $this->assertSame('WARN', $checks['DGWAY_TRUSTED_PROXIES']['result']);
        $this->assertSame('FAIL', $checks['log channel']['result']);
        $this->assertSame('WARN', $checks['scheduler heartbeat']['result']);

        config(['app.url' => 'https://api.example.test', 'dgway.trusted_proxies' => '172.16.0.0/12', 'logging.channels.stack.channels' => ['daily']]);
        file_put_contents((string) config('dgway.scheduler_heartbeat_path'), 'now');
        Artisan::call('dgway:doctor', ['--production' => true, '--json' => true]);
        $checks = $this->doctorChecks();
        foreach (['APP_URL', 'DGWAY_TRUSTED_PROXIES', 'log channel', 'scheduler heartbeat'] as $c) {
            $this->assertSame('OK', $checks[$c]['result'], $c);
        }
        @unlink((string) config('dgway.scheduler_heartbeat_path'));
    }
}
