<?php

use App\Infrastructure\Persistence\Schema\MasterDataSchema as S;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Network master data.
 *   locations = physical places
 *   nodes     = operational units with capabilities (inventory, receiving …),
 *               optionally placed at a location
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            S::addressColumns($table);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            S::versionColumn($table);
            $table->timestampsTz();
        });
        S::codeConstraint('locations');
        S::versionConstraint('locations');
        S::addressConstraints('locations');

        Schema::create('nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->foreignUuid('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            S::versionColumn($table);
            $table->timestampsTz();

            $table->index(['location_id', 'is_active']);
        });
        DB::statement("ALTER TABLE nodes ADD COLUMN capabilities text[] NOT NULL");
        S::codeConstraint('nodes');
        S::versionConstraint('nodes');
        DB::statement("ALTER TABLE nodes ADD CONSTRAINT nodes_capabilities_check CHECK (
            cardinality(capabilities) >= 1
            AND capabilities <@ ARRAY['INVENTORY','RECEIVING','FULFILLMENT','PICKUP','DELIVERY']::text[])");
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('locations');
    }
};
