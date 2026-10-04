<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory ledger (source of truth) + balance projection.
 *
 * stock_movements   append-only; UPDATE/DELETE rejected by trigger.
 *                   quantity is a positive magnitude, direction ±1;
 *                   signed_quantity is a generated column for summing.
 * stock_balances    projection per (product, node, bucket), updated in the
 *                   SAME transaction as the movements, rebuildable from the
 *                   ledger (`php artisan dgway:inventory:rebuild-balances`).
 *                   quantity ≥ 0 enforces the A3 no-negative-stock policy
 *                   at database level too.
 * inventory_operations  source documents for loss / regrade / recovery /
 *                   supplier return / adjustment (append-only).
 */
return new class extends Migration
{
    private const TYPES = "'RECEIPT','SALE','CUSTOMER_RETURN','SUPPLIER_RETURN','TRANSFER_OUT','TRANSFER_IN','LOSS','RECOVERY','REGRADE_OUT','REGRADE_IN','ADJUSTMENT_IN','ADJUSTMENT_OUT','REVERSAL'";
    private const BUCKETS = "'AVAILABLE','QUARANTINE','RETURN_PENDING'";

    public function up(): void
    {
        Schema::create('inventory_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->string('operation_type', 24);
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('target_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->decimal('quantity', 18, 4);
            $t->string('uom_code', 16);
            $t->string('from_bucket', 16);
            $t->smallInteger('adjustment_direction')->nullable();
            $t->string('reason', 500);
            $t->date('business_date');
            $t->timestampTz('occurred_at');
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->index(['node_id', 'business_date']);
        });
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_type_check CHECK (operation_type IN ('LOSS','REGRADE','RECOVERY','SUPPLIER_RETURN','ADJUSTMENT'))");
        DB::statement('ALTER TABLE inventory_operations ADD CONSTRAINT invop_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_bucket_check CHECK (from_bucket IN (".self::BUCKETS."))");
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_regrade_target_check CHECK ((operation_type = 'REGRADE') = (target_product_id IS NOT NULL))");
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_adjustment_dir_check CHECK ((operation_type = 'ADJUSTMENT') = (adjustment_direction IS NOT NULL) AND (adjustment_direction IS NULL OR adjustment_direction IN (-1, 1)))");
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_reason_check CHECK (length(trim(reason)) > 0)");

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->bigIncrements('sequence')->unique();
            $t->string('movement_type', 24);
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->string('bucket', 16);
            $t->smallInteger('direction');
            $t->decimal('quantity', 18, 4);
            $t->string('uom_code', 16);
            $t->decimal('unit_cost', 19, 4)->nullable();
            $t->char('currency', 3)->nullable();
            $t->date('business_date');
            $t->timestampTz('occurred_at', 6);
            $t->string('source_document_type', 40);
            $t->uuid('source_document_id');
            $t->uuid('source_line_id')->nullable();
            $t->uuid('correlation_id');
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('reason', 500)->nullable();
            $t->uuid('reverses_movement_id')->nullable()->unique();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->index(['product_id', 'node_id', 'bucket', 'sequence']);
            $t->index(['source_document_type', 'source_document_id']);
            $t->index('correlation_id');
            $t->index(['business_date', 'movement_type']);
        });
        // bigIncrements makes `sequence` the primary key; the UUID must be the PK.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_pkey');
        DB::statement('ALTER TABLE stock_movements ADD PRIMARY KEY (id)');
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreign('reverses_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_movements ADD COLUMN signed_quantity numeric(18,4) GENERATED ALWAYS AS (quantity * direction) STORED');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT sm_type_check CHECK (movement_type IN (".self::TYPES."))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT sm_bucket_check CHECK (bucket IN (".self::BUCKETS."))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_direction_check CHECK (direction IN (-1, 1))');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT sm_type_direction_check CHECK (
            (movement_type IN ('RECEIPT','CUSTOMER_RETURN','TRANSFER_IN','REGRADE_IN','ADJUSTMENT_IN') AND direction = 1)
            OR (movement_type IN ('SALE','SUPPLIER_RETURN','TRANSFER_OUT','LOSS','REGRADE_OUT','ADJUSTMENT_OUT') AND direction = -1)
            OR movement_type IN ('RECOVERY','REVERSAL'))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT sm_reversal_link_check CHECK ((movement_type = 'REVERSAL') = (reverses_movement_id IS NOT NULL))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_cost_check CHECK ((unit_cost IS NULL) = (currency IS NULL) AND (unit_cost IS NULL OR unit_cost >= 0))');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            CREATE TRIGGER inventory_operations_append_only BEFORE UPDATE OR DELETE ON inventory_operations
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();

            -- A reversal must exactly mirror the movement it reverses.
            CREATE OR REPLACE FUNCTION dgway_validate_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE o stock_movements%ROWTYPE;
            BEGIN
                IF NEW.reverses_movement_id IS NULL THEN RETURN NEW; END IF;
                SELECT * INTO o FROM stock_movements WHERE id = NEW.reverses_movement_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Reversed movement % does not exist', NEW.reverses_movement_id USING ERRCODE = 'foreign_key_violation';
                END IF;
                IF o.movement_type = 'REVERSAL' THEN
                    RAISE EXCEPTION 'A reversal cannot be reversed' USING ERRCODE = 'check_violation';
                END IF;
                IF (NEW.product_id, NEW.node_id, NEW.bucket, NEW.quantity, NEW.uom_code, NEW.direction)
                    IS DISTINCT FROM (o.product_id, o.node_id, o.bucket, o.quantity, o.uom_code, -o.direction) THEN
                    RAISE EXCEPTION 'Reversal must mirror movement % (same position and quantity, opposite direction)', o.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER stock_movements_reversal_check BEFORE INSERT ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION dgway_validate_reversal();
            SQL);

        Schema::create('stock_balances', function (Blueprint $t) {
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->string('bucket', 16);
            $t->string('uom_code', 16);
            $t->decimal('quantity', 18, 4)->default(0);
            $t->timestampTz('updated_at')->useCurrent();
            $t->primary(['product_id', 'node_id', 'bucket']);
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->index(['node_id', 'bucket']);
        });
        DB::statement("ALTER TABLE stock_balances ADD CONSTRAINT sb_bucket_check CHECK (bucket IN (".self::BUCKETS."))");
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT sb_non_negative_check CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_operations');
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_validate_reversal() CASCADE;');
    }
};
