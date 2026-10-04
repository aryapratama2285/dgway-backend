<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reusable database-level guard for append-only ledgers.
 *
 * Attach with:
 *   CREATE TRIGGER <table>_append_only BEFORE UPDATE OR DELETE ON <table>
 *   FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
 *
 * Used now by audit_logs; later by inventory_movements and other ledgers.
 * Corrections must be new rows (reversal / adjustment), never edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_forbid_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_forbid_mutation() CASCADE');
    }
};
