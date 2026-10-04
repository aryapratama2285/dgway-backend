<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure as reference data (open set, not a hard-coded enum).
 * KG / PCS / IKAT are inserted as the MVP baseline; new units are rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 16)->unique();
            $table->string('name', 64);
            $table->smallInteger('precision');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE uoms ADD CONSTRAINT uoms_precision_check CHECK (precision BETWEEN 0 AND 4)');
        DB::statement("ALTER TABLE uoms ADD CONSTRAINT uoms_code_format_check CHECK (code ~ '^[A-Z][A-Z0-9_]{0,15}$')");

        $now = now('UTC');
        DB::table('uoms')->insert([
            ['id' => '01928a3c-0000-7000-8000-000000000001', 'code' => 'KG', 'name' => 'Kilogram', 'precision' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['id' => '01928a3c-0000-7000-8000-000000000002', 'code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => '01928a3c-0000-7000-8000-000000000003', 'code' => 'IKAT', 'name' => 'Ikat', 'precision' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('uoms');
    }
};
