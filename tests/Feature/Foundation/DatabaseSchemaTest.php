<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Proves the A1 schema exists in PostgreSQL with its constraints. */
final class DatabaseSchemaTest extends DatabaseTestCase
{
    public function test_connection_is_postgresql_in_utc(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('UTC', DB::selectOne('SHOW timezone')->TimeZone);
        $this->assertStringStartsWith('PostgreSQL', DB::selectOne('SELECT version() AS v')->v);
    }

    public function test_foundation_tables_exist(): void
    {
        foreach (['users', 'personal_access_tokens', 'audit_logs', 'idempotency_keys', 'document_sequences', 'uoms', 'migrations'] as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), "Missing table {$table}");
        }
    }

    public function test_primary_keys_are_uuid(): void
    {
        foreach (['users', 'audit_logs', 'idempotency_keys', 'uoms'] as $table) {
            $type = DB::selectOne(
                "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'id'",
                [$table],
            )->data_type;
            $this->assertSame('uuid', $type, "{$table}.id must be uuid");
        }
    }

    public function test_timestamps_are_timezone_aware(): void
    {
        $rows = DB::select(
            "SELECT table_name, column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND data_type LIKE 'timestamp%'
               AND table_name IN ('users','personal_access_tokens','audit_logs','idempotency_keys','document_sequences','uoms')",
        );

        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('timestamp with time zone', $r->data_type, "{$r->table_name}.{$r->column_name}");
        }
    }

    public function test_uom_reference_data_is_present(): void
    {
        $uoms = DB::table('uoms')->orderBy('code')->pluck('precision', 'code')->all();

        // A2 baseline + F5A purchasing units (decision D2; their size is per product, not global)
        $this->assertSame(['BOX' => 0, 'IKAT' => 0, 'KG' => 3, 'LITER' => 3, 'PACK' => 0, 'PCS' => 0, 'ROLL' => 0, 'TRAY' => 0], $uoms);
    }

    public function test_uom_precision_check_constraint(): void
    {
        $this->expectException(QueryException::class);
        DB::table('uoms')->insert(['id' => '01928a3c-0000-7000-8000-0000000000ff', 'code' => 'BAD', 'name' => 'x', 'precision' => 9, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_user_role_check_constraint_rejects_unknown_roles(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'id' => '01928a3c-0000-7000-8000-0000000000aa', 'name' => 'x', 'email' => 'x@dgway.test',
            'password' => 'x', 'role' => 'SUPERUSER', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_user_email_must_be_lowercase(): void
    {
        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            'id' => '01928a3c-0000-7000-8000-0000000000ab', 'name' => 'x', 'email' => 'Owner@DGWay.test',
            'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_append_only_guard_function_exists(): void
    {
        $this->assertNotNull(DB::selectOne("SELECT 1 FROM pg_proc WHERE proname = 'dgway_forbid_mutation'"));
        $this->assertNotNull(DB::selectOne("SELECT 1 FROM pg_trigger WHERE tgname = 'audit_logs_append_only'"));
    }
}
