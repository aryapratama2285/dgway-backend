<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F5A — common purchasing units (decision D2).
 *
 * Adds TRAY, BOX (shown as "Dus/Box"), PACK, ROLL and LITER next to the
 * existing KG, PCS and IKAT. These are reference rows only: there is NO global
 * size for any of them. How many base units a TRAY or a BOX holds is defined
 * per product through the existing product_uom_conversions
 * (PUT /products/{id}/uom-conversions/{uom}, permission catalog.manage_uom_conversion).
 *
 * Idempotent (ON CONFLICT DO NOTHING). Fixed ids, like the A2 baseline rows.
 * Reference rows are never deleted (uoms_identity_guard refuses DELETE) and
 * never silently changed: down() deliberately leaves them in place — unused
 * extra units are harmless, and a rollback + re-migrate cycle leaves the uoms
 * table byte-identical (dgway:data:fingerprint stays equal).
 */
return new class extends Migration
{
    private const UNITS = [
        ['01928a3c-0000-7000-8000-000000000004', 'TRAY', 'Tray', 0],
        ['01928a3c-0000-7000-8000-000000000005', 'BOX', 'Dus/Box', 0],
        ['01928a3c-0000-7000-8000-000000000006', 'PACK', 'Pack', 0],
        ['01928a3c-0000-7000-8000-000000000007', 'ROLL', 'Roll', 0],
        ['01928a3c-0000-7000-8000-000000000008', 'LITER', 'Liter', 3],
    ];

    public function up(): void
    {
        foreach (self::UNITS as [$id, $code, $name, $precision]) {
            DB::statement(
                'INSERT INTO uoms (id, code, name, precision, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, true, now(), now()) ON CONFLICT (code) DO NOTHING',
                [$id, $code, $name, $precision],
            );
        }
    }

    public function down(): void
    {
        // Intentionally empty: reference rows stay (see the class comment).
    }
};
