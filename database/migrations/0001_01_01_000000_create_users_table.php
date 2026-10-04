<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator accounts (MVP: 2 owner/operators).
 *
 * - UUID primary key (portable to Project B).
 * - role is a string constrained by CHECK; new roles are added by widening
 *   the constraint, not by redesigning the table.
 * - No password-reset / session tables: the API is token-only and operator
 *   accounts are managed from the server console.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 255)->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role', 32)->default('OWNER');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('OWNER'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lowercase_check CHECK (email = lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
