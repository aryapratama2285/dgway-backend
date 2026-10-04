<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Application\Shared\Audit\AuditEntry;
use App\Application\Shared\Audit\AuditRecorder;
use App\Domain\Shared\Identity\Actor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

final class AuditLogTest extends DatabaseTestCase
{
    public function test_records_actor_timestamp_business_date_and_change(): void
    {
        $user = User::factory()->create(['name' => 'Owner Satu']);
        $this->clock->travelTo('2026-10-02T23:30:00Z'); // 06:30 WIB on 3 Oct

        $id = $this->app->make(AuditRecorder::class)->record(new AuditEntry(
            actor: Actor::operator($user->id, $user->name),
            action: 'sale.posted',
            entityType: 'sale',
            entityId: '0192f0a0-1234-7abc-8def-0123456789ab',
            before: null,
            after: ['total' => '910000.0000', 'customer' => 'Bakso Monalisa'],
            context: ['idempotency_key' => 'abc-123-def-456-ghi'],
        ));

        $row = DB::table('audit_logs')->where('id', $id)->first();

        $this->assertSame($user->id, $row->actor_user_id);
        $this->assertSame('Owner Satu', $row->actor_label);
        $this->assertSame('sale.posted', $row->action);
        $this->assertSame('2026-10-03', $row->business_date, 'business date follows Asia/Jakarta');
        $this->assertStringStartsWith('2026-10-02 23:30:00', $row->occurred_at, 'timestamp stored in UTC');
        $this->assertEquals(['customer' => 'Bakso Monalisa', 'total' => '910000.0000'], json_decode($row->after, true), 'jsonb does not preserve key order; content must match');
        $this->assertSame('abc-123-def-456-ghi', json_decode($row->context, true)['idempotency_key']);
    }

    public function test_audit_rows_cannot_be_updated(): void
    {
        $id = $this->app->make(AuditRecorder::class)->record(new AuditEntry(Actor::system(), 'x', 'test'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
    }

    public function test_audit_rows_cannot_be_deleted(): void
    {
        $id = $this->app->make(AuditRecorder::class)->record(new AuditEntry(Actor::system(), 'x', 'test'));

        try {
            DB::table('audit_logs')->where('id', $id)->delete();
            $this->fail('Delete should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(1, DB::table('audit_logs')->count());
    }
}
