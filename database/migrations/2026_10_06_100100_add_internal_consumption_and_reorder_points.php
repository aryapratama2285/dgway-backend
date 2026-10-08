<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F3 — inventory operations & internal consumption.
 *
 * 1. INTERNAL_CONSUMPTION: a first-class operation type AND movement type
 *    (OUT only). DG Way consumed its own material (packaging, labels, tape…);
 *    it is neither a LOSS nor an ADJUSTMENT_OUT.
 * 2. inventory_operations gains:
 *    - reason_code     required for INTERNAL_CONSUMPTION, optional for ADJUSTMENT,
 *                      NULL for every other type (CHECK);
 *    - reference_text  optional operational reference (≤ 60) of a consumption;
 *                      NOT an accounting link, no foreign key;
 *    - counted_quantity / system_quantity  stock-count snapshot of an ADJUSTMENT
 *                      in counted mode (delta computed by the server under lock).
 * 3. reorder_points: low-stock threshold per product × node. Configuration,
 *    not stock: no balance, no ledger. The unit is NOT stored: it is always
 *    the product's base UOM, and products.base_uom_code cannot change while
 *    an active threshold exists (trigger below + application check).
 *
 * Existing rows are untouched (ADD COLUMN only); shipped migrations are not edited.
 * down() refuses to run when F3 data exists, so a rollback can never destroy
 * history or configured thresholds.
 */
return new class extends Migration
{
    private const OLD_MOVEMENT_TYPES = "'RECEIPT','SALE','CUSTOMER_RETURN','SUPPLIER_RETURN','TRANSFER_OUT','TRANSFER_IN','LOSS','RECOVERY','REGRADE_OUT','REGRADE_IN','ADJUSTMENT_IN','ADJUSTMENT_OUT','REVERSAL'";

    private const OLD_OPERATION_TYPES = "'LOSS','REGRADE','RECOVERY','SUPPLIER_RETURN','ADJUSTMENT'";

    private const CONSUMPTION_REASONS = "'PACKING','LABELING','DELIVERY','ADMIN','SAMPLE','OTHER'";

    private const ADJUSTMENT_REASONS = "'COUNT_CORRECTION','FOUND_STOCK','SHRINKAGE','OTHER'";

    public function up(): void
    {
        Schema::table('inventory_operations', function (Blueprint $t) {
            $t->string('reason_code', 32)->nullable();
            $t->string('reference_text', 60)->nullable();
            $t->decimal('counted_quantity', 18, 4)->nullable();
            $t->decimal('system_quantity', 18, 4)->nullable();
        });

        DB::statement('ALTER TABLE inventory_operations DROP CONSTRAINT invop_type_check');
        DB::statement('ALTER TABLE inventory_operations ADD CONSTRAINT invop_type_check CHECK (operation_type IN ('.self::OLD_OPERATION_TYPES.",'INTERNAL_CONSUMPTION'))");
        DB::statement('ALTER TABLE inventory_operations ADD CONSTRAINT invop_reason_code_check CHECK (
            (operation_type = \'INTERNAL_CONSUMPTION\' AND reason_code IS NOT NULL AND reason_code IN ('.self::CONSUMPTION_REASONS.'))
            OR (operation_type = \'ADJUSTMENT\' AND (reason_code IS NULL OR reason_code IN ('.self::ADJUSTMENT_REASONS.')))
            OR (operation_type NOT IN (\'INTERNAL_CONSUMPTION\', \'ADJUSTMENT\') AND reason_code IS NULL))');
        // NB: every CHECK below is written so that a NULL can never make it pass by accident
        // (a CHECK whose expression evaluates to NULL is satisfied in SQL).
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_reference_check CHECK (
            reference_text IS NULL OR (operation_type = 'INTERNAL_CONSUMPTION' AND length(trim(reference_text)) > 0))");
        DB::statement("ALTER TABLE inventory_operations ADD CONSTRAINT invop_count_check CHECK (
            (counted_quantity IS NULL AND system_quantity IS NULL)
            OR (operation_type = 'ADJUSTMENT' AND counted_quantity IS NOT NULL AND system_quantity IS NOT NULL
                AND counted_quantity >= 0 AND system_quantity >= 0))");

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT sm_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_type_check CHECK (movement_type IN ('.self::OLD_MOVEMENT_TYPES.",'INTERNAL_CONSUMPTION'))");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT sm_type_direction_check');
        DB::statement(self::typeDirectionCheck(true));

        Schema::create('reorder_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->decimal('min_quantity', 18, 4)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['product_id', 'node_id'], 'reorder_points_product_node_unique');
            $t->index('node_id');
        });
        DB::statement('ALTER TABLE reorder_points ADD CONSTRAINT rp_min_quantity_check CHECK (min_quantity IS NULL OR min_quantity >= 0)');
        DB::statement('ALTER TABLE reorder_points ADD CONSTRAINT rp_version_check CHECK (version >= 1)');
        // master data: rows are never deleted (null = disabled) and their identity never changes
        DB::unprepared("CREATE TRIGGER reorder_points_identity_guard BEFORE UPDATE OR DELETE ON reorder_points FOR EACH ROW EXECUTE FUNCTION dgway_guard_identity('id', 'product_id', 'node_id')");

        // A threshold is expressed in the product's base UOM. Changing that UOM
        // would silently change what the number means, so it is refused while
        // an active threshold exists (clear it first, then change the UOM).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_guard_base_uom_vs_reorder_points() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.base_uom_code IS DISTINCT FROM OLD.base_uom_code
                   AND EXISTS (SELECT 1 FROM reorder_points WHERE product_id = NEW.id AND min_quantity IS NOT NULL) THEN
                    RAISE EXCEPTION 'BASE_UOM_LOCKED_BY_REORDER_POINT: product % has an active reorder point', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER products_base_uom_reorder_guard BEFORE UPDATE OF base_uom_code ON products
                FOR EACH ROW EXECUTE FUNCTION dgway_guard_base_uom_vs_reorder_points();
        SQL);
    }

    public function down(): void
    {
        $f3Operations = DB::table('inventory_operations')
            ->where(fn ($q) => $q->where('operation_type', 'INTERNAL_CONSUMPTION')
                ->orWhereNotNull('reason_code')->orWhereNotNull('counted_quantity'))
            ->count();
        $f3Movements = DB::table('stock_movements')->where('movement_type', 'INTERNAL_CONSUMPTION')->count();
        $thresholds = Schema::hasTable('reorder_points') ? DB::table('reorder_points')->count() : 0;
        if ($f3Operations + $f3Movements + $thresholds > 0) {
            throw new RuntimeException(
                "Refusing to roll back F3: {$f3Operations} F3 inventory operation(s), {$f3Movements} INTERNAL_CONSUMPTION movement(s) "
                ."and {$thresholds} reorder point(s) exist. History and configured thresholds are never destroyed by a rollback."
            );
        }

        DB::unprepared('DROP TRIGGER IF EXISTS products_base_uom_reorder_guard ON products; DROP FUNCTION IF EXISTS dgway_guard_base_uom_vs_reorder_points();');
        Schema::dropIfExists('reorder_points');

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT sm_type_direction_check');
        DB::statement(self::typeDirectionCheck(false));
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT sm_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_type_check CHECK (movement_type IN ('.self::OLD_MOVEMENT_TYPES.'))');

        DB::statement('ALTER TABLE inventory_operations DROP CONSTRAINT invop_count_check');
        DB::statement('ALTER TABLE inventory_operations DROP CONSTRAINT invop_reference_check');
        DB::statement('ALTER TABLE inventory_operations DROP CONSTRAINT invop_reason_code_check');
        DB::statement('ALTER TABLE inventory_operations DROP CONSTRAINT invop_type_check');
        DB::statement('ALTER TABLE inventory_operations ADD CONSTRAINT invop_type_check CHECK (operation_type IN ('.self::OLD_OPERATION_TYPES.'))');
        Schema::table('inventory_operations', function (Blueprint $t) {
            $t->dropColumn(['reason_code', 'reference_text', 'counted_quantity', 'system_quantity']);
        });
    }

    /** The A3 type ↔ direction rule, optionally with INTERNAL_CONSUMPTION as an OUT-only type. */
    private static function typeDirectionCheck(bool $withConsumption): string
    {
        $out = "'SALE','SUPPLIER_RETURN','TRANSFER_OUT','LOSS','REGRADE_OUT','ADJUSTMENT_OUT'".($withConsumption ? ",'INTERNAL_CONSUMPTION'" : '');

        return "ALTER TABLE stock_movements ADD CONSTRAINT sm_type_direction_check CHECK (
            (movement_type IN ('RECEIPT','CUSTOMER_RETURN','TRANSFER_IN','REGRADE_IN','ADJUSTMENT_IN') AND direction = 1)
            OR (movement_type IN ({$out}) AND direction = -1)
            OR movement_type IN ('RECOVERY','REVERSAL'))";
    }
};
