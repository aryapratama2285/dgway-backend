<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A1 hardening: `jsonb` re-orders object keys, so a replayed response had the
 * same content but a different key order than the original. `json` stores
 * the text verbatim, making replays byte-for-byte identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_body TYPE json USING response_body::json');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_body TYPE jsonb USING response_body::jsonb');
    }
};
