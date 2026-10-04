<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A5 addendum §1 (stable identity), Generation-1 data contract.
 *
 * Master records and operators are the identities every later system
 * generation (Project B) builds on. The application never deletes them or
 * changes their UUID / business key; this makes the database refuse it too,
 * even for unreferenced rows and raw SQL:
 *   - DELETE is rejected (deactivate instead);
 *   - UPDATE of the identity columns given as trigger arguments is rejected.
 * Ordinary edits (names, terms, active flags …) are unaffected.
 */
return new class extends Migration
{
    /** table => immutable identity columns */
    private const GUARDED = [
        'product_categories' => ['id', 'code'],
        'products' => ['id', 'sku'],
        'business_partners' => ['id', 'code'],
        'business_partner_roles' => ['id', 'partner_id', 'role'],
        'locations' => ['id', 'code'],
        'nodes' => ['id', 'code'],
        'uoms' => ['id', 'code'],
        'users' => ['id'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_guard_identity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE col text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Table % holds stable identities: rows are never deleted (deactivate instead)', TG_TABLE_NAME
                        USING ERRCODE = 'restrict_violation';
                END IF;
                FOREACH col IN ARRAY TG_ARGV LOOP
                    IF (to_jsonb(NEW) -> col) IS DISTINCT FROM (to_jsonb(OLD) -> col) THEN
                        RAISE EXCEPTION 'Identity column %.% is immutable', TG_TABLE_NAME, col
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;
                RETURN NEW;
            END; $$;
        SQL);

        foreach (self::GUARDED as $table => $columns) {
            $args = implode(', ', array_map(fn ($c) => "'{$c}'", $columns));
            DB::unprepared("CREATE TRIGGER {$table}_identity_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION dgway_guard_identity({$args})");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GUARDED) as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_identity_guard ON {$table}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_guard_identity()');
    }
};
