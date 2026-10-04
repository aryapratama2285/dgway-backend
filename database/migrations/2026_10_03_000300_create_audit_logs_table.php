<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail: who, when, what action, which entity, what changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at', 6);
            $table->date('business_date');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_label', 120);
            $table->string('action', 100);
            $table->string('entity_type', 64);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('context')->default('{}');

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['business_date']);
            $table->index(['action']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_append_only
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
