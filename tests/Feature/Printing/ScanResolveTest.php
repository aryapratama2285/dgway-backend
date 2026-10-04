<?php

declare(strict_types=1);

namespace Tests\Feature\Printing;

use App\Application\Printing\IdentifierCodec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Lots\LotTestCase;

/** A5-C §7–10 — QR/barcode payloads and the single scan resolver. */
final class ScanResolveTest extends LotTestCase
{
    private function scan(string $code)
    {
        return $this->get_('scan/resolve?code='.rawurlencode($code));
    }

    public function test_qr_payload_is_deterministic_and_carries_only_the_stable_id(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '150', null, null, ['collection_date' => '2026-10-02', 'supplier_reference' => 'FARM-7']);
        $abc = $this->customer('CUS-CATERING-ABC', 7, '5000000');
        $sale = $this->sell($abc, '50', '25500', true, [$this->cash('775000')])->json('data');
        $payloads = [
            $this->get_("lots/{$lot}/label")->json('data.document.codes.qr.payload'),
            $this->get_("lots/{$lot}/label")->json('data.document.codes.qr.payload'),
            IdentifierCodec::qr('lot', strtoupper($lot)),
        ];
        $this->assertSame(array_fill(0, 3, "dgway://lot/{$lot}"), $payloads, 'same lot → same payload, lower-case canonical');
        $this->assertMatchesRegularExpression('#^dgway://lot/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $payloads[0]);

        // nothing sensitive or mutable: only scheme, entity and UUID
        $receiptQr = $this->get_("sales/{$sale['id']}/print")->json('data.document.reference.qr_payload');
        $this->assertSame("dgway://sale/{$sale['id']}", $receiptQr);
        $phone = (string) DB::table('business_partners')->where('id', $abc)->value('phone');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => 'password', 'device_name' => 'x'])->json('data.token') ?? 'no-token';
        foreach ([$payloads[0], $receiptQr] as $qr) {
            foreach (['150', '25500', '775000', '500000', 'FARM-7', '2026-10-02', 'SUP-BANDUNG', 'CUS-CATERING-ABC', $phone, substr((string) $token, 0, 12), 'token', 'key', '?'] as $secret) {
                $this->assertStringNotContainsString($secret, $qr);
            }
        }
        $barcode = $this->get_("lots/{$lot}/label")->json('data.document.codes.barcode');
        $this->assertSame(['symbology' => 'CODE128', 'value' => DB::table('inventory_lots')->where('id', $lot)->value('number')], $barcode);
    }

    public function test_qr_lot_number_and_sku_resolve_to_the_same_typed_entities(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '150', [['type' => 'ACCEPT', 'quantity' => '140'], ['type' => 'LOSS', 'quantity' => '10']]);
        $number = DB::table('inventory_lots')->where('id', $lot)->value('number');

        $qr = $this->scan("dgway://lot/{$lot}")->assertOk()->json('data');
        $this->assertSame(['LOT', $lot, 'QR'], [$qr['type'], $qr['id'], $qr['matched_by']]);
        $this->assertSame([$number, 'TELUR-STD', 'ACTIVE', '140.0000', 'KG'], [$qr['display']['lot_number'], $qr['display']['sku'], $qr['display']['status'],
            $qr['display']['available']['value'], $qr['display']['available']['uom']]);
        $this->assertSame(["/api/v1/lots/{$lot}", "/api/v1/lots/{$lot}/label"], array_values($qr['links']));
        foreach (["  {$number}  ", strtolower($number), "DGWAY://LOT/".strtoupper($lot), "dgway://v1/lot/{$lot}"] as $variant) {
            $this->assertSame($lot, $this->scan($variant)->assertOk()->json('data.id'), $variant);
        }
        $this->assertSame('LOT_NUMBER', $this->scan($number)->json('data.matched_by'));

        $sku = $this->scan('telur-std')->assertOk()->json('data');
        $this->assertSame(['PRODUCT', $this->egg, 'SKU', 'TELUR-STD', 'KG'], [$sku['type'], $sku['id'], $sku['matched_by'], $sku['display']['sku'], $sku['display']['base_uom']]);
        $this->assertSame($this->egg, $this->scan("dgway://product/{$this->egg}")->assertOk()->json('data.id'));
    }

    public function test_unknown_invalid_and_future_codes_have_stable_errors_without_leaking(): void
    {
        $this->receiveOn('2026-10-03', '10');
        $unknown = [$this->scan('LOT-999999-999'), $this->scan('dgway://lot/'.Str::uuid()), $this->scan("dgway://sale/{$this->egg}") /* a product id as a sale */];
        foreach ($unknown as $res) {
            $res->assertStatus(404)->assertExactJson(['error' => ['code' => 'SCAN_CODE_UNKNOWN', 'message' => 'No DG Way record matches this code.']]);
        }
        $this->scan('dgway://v2/lot/'.Str::uuid())->assertStatus(422)->assertJsonPath('error.code', 'SCAN_VERSION_UNSUPPORTED');
        foreach (['dgway://lot/not-a-uuid', 'dgway://customer/'.Str::uuid(), str_repeat('A', 129), "LOT\x07"] as $bad) {
            $this->scan($bad)->assertStatus(422)->assertJsonPath('error.code', 'SCAN_CODE_INVALID');
        }
        $this->get_('scan/resolve')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringNotContainsString('LOT-999999-999', (string) $unknown[0]->getContent(), 'the scanned text is never echoed');
    }

    public function test_ambiguous_text_is_refused_deterministically_while_the_qr_still_resolves(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '10');
        $number = DB::table('inventory_lots')->where('id', $lot)->value('number');
        $clash = $this->product($number); // a SKU that happens to equal a lot number
        foreach ([1, 2] as $attempt) {
            $this->scan($number)->assertStatus(409)->assertJsonPath('error.code', 'SCAN_CODE_AMBIGUOUS')
                ->assertJsonPath('error.details.candidate_types', ['LOT', 'PRODUCT']);
        }
        $this->assertSame($lot, $this->scan("dgway://lot/{$lot}")->assertOk()->json('data.id'));
        $this->assertSame($clash, $this->scan("dgway://product/{$clash}")->assertOk()->json('data.id'));
    }

    public function test_resolver_requires_authentication_and_has_no_side_effects(): void
    {
        [, $lot] = $this->receiveOn('2026-10-03', '10');
        $before = [DB::table('audit_logs')->count(), DB::table('idempotency_keys')->count(), DB::table('stock_movements')->count()];
        $this->scan("dgway://lot/{$lot}")->assertOk();
        $this->scan('LOT-999999-999')->assertStatus(404);
        $this->get_("lots/{$lot}/label")->assertOk();
        $this->assertSame($before, [DB::table('audit_logs')->count(), DB::table('idempotency_keys')->count(), DB::table('stock_movements')->count()],
            'scanning and printing are read-only: no audit, no idempotency key, no movement');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer invalid')->getJson('/api/v1/scan/resolve?code='.rawurlencode("dgway://lot/{$lot}"))
            ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->withHeader('Authorization', 'Bearer invalid')->getJson("/api/v1/lots/{$lot}/label")->assertStatus(401);
    }
}
