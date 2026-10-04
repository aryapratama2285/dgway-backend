<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per successfully committed idempotent command.
 * Unique per (actor_scope, idempotency_key): the same key from the same
 * operator can never create a second transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('actor_scope', 64);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('command', 100);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();

            $table->unique(['actor_scope', 'idempotency_key']);
            $table->index(['resource_type', 'resource_id']);
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('PENDING', 'COMPLETED'))");
        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_completed_check CHECK (status <> 'COMPLETED' OR (response_status IS NOT NULL AND response_body IS NOT NULL AND completed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
