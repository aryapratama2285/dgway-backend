<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkerProcess;

/** A5-B §18 — OWNER-only, reasoned, audited credit-limit override; the limit itself never changes. */
final class CreditLimitOverrideTest extends SalesTestCase
{
    private string $abc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stockUp('200');
        $this->abc = $this->customer('CUS-CATERING-ABC', 14, '1000000');
    }

    private function limit(): string
    {
        return (string) DB::table('business_partner_roles')->where('partner_id', $this->abc)->where('role', 'CUSTOMER')->value('credit_limit');
    }

    private function override(string $saleId, array $body = ['reason' => 'Pelanggan lama, disetujui owner'], ?string $key = null)
    {
        return $this->cmd('POST', "sales/{$saleId}/confirm-with-credit-override", $body, $key);
    }

    public function test_owner_can_override_an_exceeded_limit_with_a_reason_and_it_is_audited(): void
    {
        $this->sell($this->abc, '28', '25000', true)->assertCreated();                // owes 700 000
        $sale = $this->sell($this->abc, '20', '25000', false)->json('data');        // +500 000 > 1 000 000
        $this->cmd('POST', "sales/{$sale['id']}/confirm")->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED');

        $key = (string) Str::uuid();
        $res = $this->override($sale['id'], ['reason' => 'Pelanggan lama, disetujui owner'], $key)->assertOk();
        $res->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.outstanding', '500000.0000');
        $this->assertSame('1000000.0000', $this->limit(), 'the credit limit itself is unchanged');
        $this->get_('receivables/customers')->assertJsonPath('data.0.outstanding', '1200000.0000');

        $row = DB::table('audit_logs')->where('action', 'sale.credit_limit_overridden')->sole();
        $after = json_decode((string) $row->after, true);
        $ctx = json_decode((string) $row->context, true);
        $this->assertSame([$sale['id'], $this->abc, $this->owner->id, 'Pelanggan lama, disetujui owner'], [$after['sale_id'], $after['customer_id'], $after['approved_by'], $after['reason']]);
        $this->assertSame(['1000000.0000', '700000.0000', '500000.0000', '1200000.0000'], [$after['credit_limit'], $after['exposure_before'], $after['requested_credit'], $after['exposure_after']]);
        $this->assertNotEmpty($after['overridden_at']);
        $this->assertSame($this->owner->id, $row->actor_user_id);
        $this->assertSame($key, $ctx['idempotency_key']);
        $this->assertSame('sale.confirm_with_credit_override', $ctx['command'] ?? null);

        // retry with the same key: replayed, no second audit entry, no second stock movement
        $this->override($sale['id'], ['reason' => 'Pelanggan lama, disetujui owner'], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sale.credit_limit_overridden')->count());
        $this->assertSame(1, DB::table('stock_movements')->where('source_document_id', $sale['id'])->count());
    }

    public function test_reason_is_mandatory_and_the_override_is_only_for_an_actually_exceeded_limit(): void
    {
        $sale = $this->sell($this->abc, '20', '25000', false)->json('data'); // 500 000 within the limit
        $this->override($sale['id'], [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->override($sale['id'], ['reason' => '   '])->assertStatus(422);
        $this->override($sale['id'], ['reason' => 'ok'])->assertStatus(422);
        $this->override($sale['id'])->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_OVERRIDE_NOT_REQUIRED');

        // the override never turns a cash customer or a walk-in into credit
        $cash = $this->customer('CUS-CASH', 0);
        $cashSale = $this->sell($cash, '10', '25000', false)->json('data');
        $this->override($cashSale['id'])->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_NOT_ALLOWED');
        $walkIn = $this->sell(null, '10', '25000', false)->json('data');
        $this->override($walkIn['id'])->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_NOT_ALLOWED');

        $this->assertSame(0, DB::table('audit_logs')->where('action', 'sale.credit_limit_overridden')->count());
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.status', 'DRAFT');
    }

    public function test_an_operator_without_the_permission_is_denied(): void
    {
        config(['dgway.authorization.role_permissions.OWNER' => []]); // a future role without the permission
        $sale = $this->sell($this->abc, '48', '25000', false)->json('data');
        $this->override($sale['id'])->assertStatus(403)->assertJsonPath('error.code', 'ACCESS_DENIED')
            ->assertJsonPath('error.details.permission', 'sales.override_credit_limit');
        $this->get_("sales/{$sale['id']}")->assertJsonPath('data.status', 'DRAFT');
        $this->assertSame(0, DB::table('stock_movements')->where('source_document_id', $sale['id'])->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'sale.credit_limit_overridden')->count());
    }

    /** Two overrides for the same customer at once: serialized on the credit lock, each audits the exposure it really saw. */
    public function test_concurrent_overrides_see_each_others_exposure(): void
    {
        $a = $this->sell($this->abc, '48', '25000', false)->json('data'); // 1 200 000
        $b = $this->sell($this->abc, '44', '25000', false)->json('data'); // 1 100 000
        $w = new WorkerProcess('hold-confirm-override', [$this->owner->id, $a['id'], '1000']);
        $w->waitFor('ACQUIRED');

        $t = microtime(true);
        $res = $this->override($b['id']);
        $waited = (microtime(true) - $t) * 1000;
        $this->assertSame('DONE', $w->waitFor('DONE'));
        $this->assertSame(0, $w->finish());

        $res->assertOk();
        $this->assertGreaterThan(500, $waited, 'B waited on the customer credit lock');
        // (worker runs on the real clock, this process on a frozen one: compare per sale, not by time)
        $audits = DB::table('audit_logs')->where('action', 'sale.credit_limit_overridden')->get()
            ->map(fn ($r) => json_decode((string) $r->after, true))->keyBy('sale_id');
        $this->assertSame(['0.0000', '1200000.0000'], [$audits[$a['id']]['exposure_before'], $audits[$a['id']]['exposure_after']]);
        $this->assertSame(['1200000.0000', '2300000.0000'], [$audits[$b['id']]['exposure_before'], $audits[$b['id']]['exposure_after']]);
        $this->assertSame('1000000.0000', $this->limit());
    }
}
