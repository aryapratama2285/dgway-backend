<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-prefix, per-business-date counters for human-readable document numbers
 * (SO-261003-001, GR-…, EGG-261003-001 …). Updated with an atomic UPSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('prefix', 32);
            $table->date('sequence_date');
            $table->bigInteger('last_value');
            $table->timestampsTz();

            $table->primary(['prefix', 'sequence_date']);
        });

        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_last_value_check CHECK (last_value >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
