<?php

declare(strict_types=1);

namespace Tests\Feature\Printing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

/** A5-C §3 — configurable business identity, default DG WAY, 58/80 mm, no printer hardware. */
final class PrintSettingsTest extends SalesTestCase
{
    public function test_defaults_are_dg_way_supply_and_distribution_at_80_mm(): void
    {
        $this->get_('print/settings')->assertOk()->assertExactJson(['data' => [
            'business_name' => 'DG WAY', 'business_subtitle' => 'Supply & Distribution', 'address' => null, 'phone' => null, 'footer_text' => null,
            'receipt_width_mm' => 80, 'supported_receipt_widths_mm' => [58, 80], 'version' => 1, 'is_default' => true,
        ]]);
        $this->assertSame(0, DB::table('print_settings')->count(), 'defaults are served, not stored');
    }

    public function test_owner_updates_identity_and_optional_fields_behave(): void
    {
        $key = (string) Str::uuid();
        $body = ['address' => '  Jl. Contoh   No. 1, Bandung ', 'phone' => '0812-0000-0000', 'footer_text' => 'Terima kasih', 'receipt_width_mm' => 58, 'expected_version' => 1];
        $this->cmd('PATCH', 'print/settings', $body, $key)->assertOk()
            ->assertJsonPath('data.address', 'Jl. Contoh No. 1, Bandung')->assertJsonPath('data.business_name', 'DG WAY')
            ->assertJsonPath('data.receipt_width_mm', 58)->assertJsonPath('data.version', 2)->assertJsonPath('data.is_default', false);
        $this->cmd('PATCH', 'print/settings', $body, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('print_settings')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'print_settings.updated')->count());

        // every print document uses the stored identity and width
        $this->stockUp('10');
        $sale = $this->sell(null, '1', '27000', true, [$this->cash('27000')])->json('data');
        $r = $this->get_("sales/{$sale['id']}/print")->json('data');
        $this->assertSame(['DG WAY', 'Supply & Distribution', 'Jl. Contoh No. 1, Bandung', '0812-0000-0000', 'Terima kasih'], array_values($r['business']));
        $this->assertSame(58, $r['layout']['paper_width_mm']);
        $this->assertSame(80, $this->get_("sales/{$sale['id']}/print?width=80")->json('data.layout.paper_width_mm'), 'per-print override');

        // null clears an optional field; absent keys stay; blank subtitle = null
        $this->cmd('PATCH', 'print/settings', ['phone' => null, 'business_subtitle' => '   '])->assertOk()
            ->assertJsonPath('data.phone', null)->assertJsonPath('data.business_subtitle', null)->assertJsonPath('data.footer_text', 'Terima kasih')->assertJsonPath('data.version', 3);
        $this->assertArrayNotHasKey('business_subtitle', array_filter($this->get_("sales/{$sale['id']}/print")->json('data.business')));
    }

    public function test_width_and_field_validation_and_stale_version(): void
    {
        foreach ([72, 0, '80mm'] as $bad) {
            $this->cmd('PATCH', 'print/settings', ['receipt_width_mm' => $bad])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $this->cmd('PATCH', 'print/settings', ['business_name' => ''])->assertStatus(422);
        $this->cmd('PATCH', 'print/settings', ['business_name' => '   '])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED'); // trimmed to empty by HTTP
        $this->cmd('PATCH', 'print/settings', ['footer_text' => str_repeat('x', 201)])->assertStatus(422);
        $this->cmd('PATCH', 'print/settings', ['receipt_width_mm' => 80, 'expected_version' => 7])->assertStatus(409)->assertJsonPath('error.code', 'CONCURRENT_MODIFICATION');
        // no printer hardware in the contract
        $this->cmd('PATCH', 'print/settings', ['bluetooth_mac' => 'AA:BB:CC:DD:EE:FF', 'business_name' => 'DG WAY'])->assertOk();
        $this->assertStringNotContainsString('AA:BB', (string) json_encode(DB::table('print_settings')->first()));
        $this->assertSame(0, DB::table('print_settings')->where('business_name', '<>', 'DG WAY')->count());
    }

    public function test_only_permitted_operators_may_change_settings(): void
    {
        config(['dgway.authorization.role_permissions.OWNER' => []]);
        $this->cmd('PATCH', 'print/settings', ['receipt_width_mm' => 58])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonPath('error.details.permission', 'settings.manage_print');
        $this->get_('print/settings')->assertOk()->assertJsonPath('data.receipt_width_mm', 80);
    }
}
