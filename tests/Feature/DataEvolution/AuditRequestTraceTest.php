<?php

declare(strict_types=1);

namespace Tests\Feature\DataEvolution;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

/**
 * A5 addendum §10 — an audit entry can be traced back to the client request
 * that caused it: every entry written inside an idempotent command carries
 * the Idempotency-Key and command name in its context.
 * (The A1 retention docblock already claimed this; it was not true before A5.)
 */
final class AuditRequestTraceTest extends SalesTestCase
{
    public function test_audit_entries_carry_the_idempotency_key_of_their_request(): void
    {
        $this->stockUp('10');
        $key = (string) Str::uuid();
        $sale = $this->sell(null, '2', '26000', true, [$this->cash('52000')], $key)->assertCreated()->json('data');

        $rows = DB::table('audit_logs')->whereIn('entity_id', [$sale['id'], DB::table('payments')->value('id')])->get();
        $this->assertGreaterThanOrEqual(4, $rows->count()); // sale.created, sale.confirmed, inventory.sale, payment.recorded, sale.completed
        foreach ($rows as $r) {
            $ctx = json_decode($r->context, true);
            $this->assertSame($key, $ctx['idempotency_key'] ?? null, $r->action);
            $this->assertSame('sale.create', $ctx['command'] ?? null, $r->action);
        }
        $this->assertSame($sale['number'], json_decode($rows->firstWhere('action', 'payment.recorded')->context, true)['sale']); // own context kept

        // entries written outside a client request (console, jobs) carry no key, and the scope does not leak
        $cat = $this->app->make(\App\Application\Catalog\CreateCategory::class)->execute(\App\Domain\Shared\Identity\Actor::system('console'), 'CONSOLE-CAT', 'Console');
        $this->assertArrayNotHasKey('idempotency_key', json_decode((string) DB::table('audit_logs')->where('entity_id', $cat->id()->value)->value('context'), true));
    }
}
