<?php

use App\Infrastructure\Persistence\Schema\MasterDataSchema as S;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business partners (one identity) and their roles (SUPPLIER / CUSTOMER).
 *
 * Role-specific columns live on business_partner_roles and are guarded by
 * CHECK constraints (customer_type only for customers, no credit limit for
 * suppliers, walk-in customers are cash only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('legal_name', 150)->nullable();
            $table->string('party_type', 16);
            $table->string('phone', 20)->nullable()->index();
            $table->string('email', 255)->nullable();
            S::addressColumns($table);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            S::versionColumn($table);
            $table->timestampsTz();
        });
        S::codeConstraint('business_partners');
        S::versionConstraint('business_partners');
        S::addressConstraints('business_partners');
        DB::statement("ALTER TABLE business_partners ADD CONSTRAINT business_partners_party_type_check CHECK (party_type IN ('INDIVIDUAL', 'ORGANIZATION'))");
        DB::statement("ALTER TABLE business_partners ADD CONSTRAINT business_partners_phone_e164_check CHECK (phone IS NULL OR phone ~ '^\\+[1-9][0-9]{7,14}$')");
        DB::statement('ALTER TABLE business_partners ADD CONSTRAINT business_partners_email_lowercase_check CHECK (email IS NULL OR email = lower(email))');
        DB::statement('CREATE INDEX business_partners_name_lower_idx ON business_partners (lower(name))');

        Schema::create('business_partner_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('partner_id')->constrained('business_partners')->restrictOnDelete();
            $table->string('role', 16);
            $table->boolean('is_active')->default(true);
            $table->smallInteger('payment_term_days')->default(0);
            $table->decimal('credit_limit', 19, 4)->nullable();
            $table->string('customer_type', 16)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['partner_id', 'role']);
            $table->index(['role', 'is_active']);
        });
        DB::statement("ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_role_check CHECK (role IN ('SUPPLIER', 'CUSTOMER'))");
        DB::statement('ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_term_days_check CHECK (payment_term_days BETWEEN 0 AND 365)');
        DB::statement('ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');
        // NOTE: `x IN (...)` is NULL (not false) when x is NULL, and a CHECK only
        // rejects FALSE — hence the explicit IS NOT NULL.
        DB::statement("ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_customer_type_check CHECK (
            (role = 'CUSTOMER' AND customer_type IS NOT NULL
                AND customer_type IN ('RETAIL','UMKM','CATERING','RESTAURANT','RESELLER','CORPORATE','WALK_IN','OTHER'))
            OR (role <> 'CUSTOMER' AND customer_type IS NULL))");
        DB::statement("ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_supplier_no_credit_check CHECK (role <> 'SUPPLIER' OR credit_limit IS NULL)");
        DB::statement("ALTER TABLE business_partner_roles ADD CONSTRAINT bpr_walk_in_cash_check CHECK (
            customer_type IS DISTINCT FROM 'WALK_IN' OR (payment_term_days = 0 AND credit_limit IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('business_partner_roles');
        Schema::dropIfExists('business_partners');
    }
};
