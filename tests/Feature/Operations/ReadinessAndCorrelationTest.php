<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

/** A5-B §21 — readiness probe without leaks; request id ↔ log ↔ audit ↔ idempotency ↔ document. */
final class ReadinessAndCorrelationTest extends SalesTestCase
{
    public function test_ready_when_database_migrations_key_and_bootstrap_are_in_place(): void
    {
        $this->getJson('/api/v1/health/ready')->assertOk()->assertExactJson(['data' => ['status' => 'ready', 'checks' => [
            'database' => 'ok', 'migrations' => 'ok', 'app_key' => 'ok', 'default_node' => 'ok', 'timezone' => 'ok',
        ]]]);
    }

    public function test_not_ready_without_bootstrap_or_with_a_bad_timezone(): void
    {
        config(['dgway.default_node_code' => 'NOT-BOOTSTRAPPED', 'dgway.business_timezone' => 'Mars/Olympus']);
        $this->getJson('/api/v1/health/ready')->assertStatus(503)
            ->assertJsonPath('data.status', 'not_ready')->assertJsonPath('data.checks.default_node', 'missing')->assertJsonPath('data.checks.timezone', 'invalid');
    }

    public function test_database_failure_is_reported_without_leaking_connection_details(): void
    {
        $original = config('database.connections.pgsql');
        $secret = 'wrong-'.Str::random(12);
        try {
            config(['database.connections.pgsql.password' => $secret, 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 1]);
            DB::purge('pgsql');
            foreach (['/api/v1/health/ready', '/api/v1/health'] as $uri) {
                $res = $this->getJson($uri)->assertStatus(503);
                $body = (string) $res->getContent();
                foreach ([$secret, 'SQLSTATE', '127.0.0.1', 'pgsql', (string) $original['database'], (string) $original['username']] as $leak) {
                    $this->assertStringNotContainsString($leak, $body, "{$uri} leaks {$leak}");
                }
            }
            $this->getJson('/api/v1/health/ready')->assertJsonPath('data.checks.database', 'fail');
        } finally {
            config(['database.connections.pgsql' => $original]);
            DB::purge('pgsql');
        }
    }

    public function test_request_id_links_response_log_audit_idempotency_and_document(): void
    {
        $this->stockUp('100');
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });
        $key = (string) Str::uuid();
        $res = $this->withHeaders(['X-Request-Id' => 'mobile-req-0001'])
            ->postJson('/api/v1/sales', ['node_id' => $this->node, 'confirm' => true, 'lines' => [['product_id' => $this->egg, 'quantity' => '2', 'unit_price' => '27000']],
                'payments' => [['method' => 'CASH', 'amount' => '54000']]], ['Idempotency-Key' => $key])->assertCreated();
        $res->assertHeader('X-Request-Id', 'mobile-req-0001');
        $saleId = $res->json('data.id');

        $line = collect($logged)->firstWhere('message', 'api.command');
        $this->assertNotNull($line, 'one structured line per command');
        $this->assertSame(['sale.create', 201, false, 'sale', $saleId], [$line['context']['command'], $line['context']['status'], $line['context']['replayed'], $line['context']['resource_type'], $line['context']['resource_id']]);
        $shared = Log::sharedContext();
        $this->assertSame(['mobile-req-0001', $key, $this->owner->id], [$shared['request_id'], $shared['idempotency_key'], $shared['actor_user_id']]);

        $audits = DB::table('audit_logs')->where('entity_id', $saleId)->get();
        $this->assertNotEmpty($audits);
        foreach ($audits as $a) {
            $ctx = json_decode((string) $a->context, true);
            $this->assertSame(['mobile-req-0001', $key], [$ctx['request_id'] ?? null, $ctx['idempotency_key'] ?? null], $a->action);
        }

        // unsafe client ids are replaced, never echoed
        $this->withHeaders(['X-Request-Id' => "evil\nheader<script>"])->getJson('/api/v1/health')->assertHeader('X-Request-Id');
        $generated = $this->withHeaders(['X-Request-Id' => 'x'])->getJson('/api/v1/health')->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $generated);
    }
}
