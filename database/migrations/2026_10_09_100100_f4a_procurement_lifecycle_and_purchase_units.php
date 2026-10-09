<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F4A — procurement lifecycle & purchasing units.
 *
 * 1. purchase_orders: CLOSED (terminal, with a reason) and lifecycle timestamps
 *    (confirmed/cancelled/closed at+by). A header guard trigger is added: the
 *    status machine, terms frozen after DRAFT, CLOSED/CANCELLED read-only.
 * 2. Purchasing units: per-product conversions (product_uom_conversions) and a
 *    SNAPSHOT of the unit, quantity, price/cost and factor on every PO line and
 *    goods-receipt line. The base columns (ordered/received quantity, unit_cost)
 *    keep their meaning; line totals are exact in the purchasing unit and the
 *    base unit cost is price ÷ factor rounded half-up to 4 decimals.
 * 3. goods_receipt_dispositions: QUARANTINE (held in the QUARANTINE bucket).
 * 4. DRAFT documents become editable: the line guards allow insert/update/delete
 *    while the parent is DRAFT (new guard functions; the old ones stay for down()).
 *
 * Shipped migrations are not edited. Existing rows are backfilled so that every
 * new CHECK holds (unit = base unit, factor = 1). down() refuses when F4A data exists.
 */
return new class extends Migration
{
    private const CLOSE_REASONS = "'SUPPLIER_SHORTFALL','PURCHASE_STOPPED','OTHER'";

    public function up(): void
    {
        // ── 1. purchase order lifecycle ──────────────────────────────────────
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->timestampTz('confirmed_at')->nullable();
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('closed_at')->nullable();
            $t->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('close_reason_code', 32)->nullable();
            $t->string('close_note', 500)->nullable();
        });

        // Backfill: the audit trail knows when a PO was confirmed / cancelled; fall back to updated_at.
        DB::statement(<<<'SQL'
            UPDATE purchase_orders o SET confirmed_at = COALESCE(
                (SELECT min(a.occurred_at) FROM audit_logs a WHERE a.entity_type = 'purchase_order' AND a.entity_id = o.id AND a.action = 'purchase_order.confirmed'),
                o.updated_at, o.created_at, now())
             WHERE o.status IN ('CONFIRMED','PARTIALLY_RECEIVED','RECEIVED')
                OR (o.status = 'CANCELLED' AND EXISTS (SELECT 1 FROM audit_logs a WHERE a.entity_type = 'purchase_order' AND a.entity_id = o.id AND a.action = 'purchase_order.confirmed'))
        SQL);
        DB::statement(<<<'SQL'
            UPDATE purchase_orders o SET cancelled_at = COALESCE(
                (SELECT min(a.occurred_at) FROM audit_logs a WHERE a.entity_type = 'purchase_order' AND a.entity_id = o.id AND a.action = 'purchase_order.cancelled'),
                o.updated_at, o.created_at, now())
             WHERE o.status = 'CANCELLED'
        SQL);

        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT po_status_check');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_status_check CHECK (status IN ('DRAFT','CONFIRMED','PARTIALLY_RECEIVED','RECEIVED','CLOSED','CANCELLED'))");
        // NULL-safe: every expression below is TRUE or FALSE, never NULL.
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_confirmed_at_check CHECK (
            status NOT IN ('CONFIRMED','PARTIALLY_RECEIVED','RECEIVED','CLOSED') OR confirmed_at IS NOT NULL)");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_cancelled_at_check CHECK ((status = 'CANCELLED') = (cancelled_at IS NOT NULL))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_closed_check CHECK (
            (status = 'CLOSED') = (closed_at IS NOT NULL)
            AND (status = 'CLOSED') = (close_reason_code IS NOT NULL)
            AND (close_reason_code IS NULL OR close_reason_code IN (".self::CLOSE_REASONS."))
            AND (close_note IS NULL OR (close_reason_code IS NOT NULL AND btrim(close_note) <> ''))
            AND (close_reason_code IS DISTINCT FROM 'OTHER' OR close_note IS NOT NULL))");
        DB::statement("CREATE INDEX purchase_orders_open_idx ON purchase_orders (node_id, business_date) WHERE status IN ('CONFIRMED','PARTIALLY_RECEIVED')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_purchase_order_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('CLOSED','CANCELLED') THEN
                    RAISE EXCEPTION 'Purchase order % is % (terminal) and read-only', OLD.number, OLD.status USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.number IS DISTINCT FROM OLD.number
                   OR (OLD.status <> 'DRAFT' AND (NEW.supplier_id, NEW.node_id, NEW.business_date, NEW.currency)
                        IS DISTINCT FROM (OLD.supplier_id, OLD.node_id, OLD.business_date, OLD.currency)) THEN
                    RAISE EXCEPTION 'Purchase order % terms are frozen after DRAFT', OLD.number USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('CONFIRMED','CANCELLED')) OR
                    (OLD.status = 'CONFIRMED' AND NEW.status IN ('PARTIALLY_RECEIVED','RECEIVED','CANCELLED')) OR
                    (OLD.status = 'PARTIALLY_RECEIVED' AND NEW.status IN ('RECEIVED','CONFIRMED','CLOSED')) OR
                    (OLD.status = 'RECEIVED' AND NEW.status IN ('PARTIALLY_RECEIVED','CONFIRMED','CLOSED'))) THEN
                    RAISE EXCEPTION 'Invalid purchase order transition % -> %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER purchase_orders_guard BEFORE UPDATE ON purchase_orders
                FOR EACH ROW EXECUTE FUNCTION dgway_purchase_order_guard();
        SQL);

        // ── 2. purchasing units ──────────────────────────────────────────────
        Schema::create('product_uom_conversions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->decimal('factor_to_base', 18, 6);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['product_id', 'uom_code'], 'product_uom_conversions_product_uom_unique');
        });
        DB::statement('ALTER TABLE product_uom_conversions ADD CONSTRAINT puc_factor_check CHECK (factor_to_base > 0)');
        DB::statement('ALTER TABLE product_uom_conversions ADD CONSTRAINT puc_version_check CHECK (version >= 1)');
        DB::unprepared("CREATE TRIGGER product_uom_conversions_identity_guard BEFORE UPDATE OR DELETE ON product_uom_conversions FOR EACH ROW EXECUTE FUNCTION dgway_guard_identity('id', 'product_id', 'uom_code')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_uom_conversion_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM products WHERE id = NEW.product_id AND base_uom_code = NEW.uom_code) THEN
                    RAISE EXCEPTION 'UOM_CONVERSION_INVALID: % is the base unit of product %', NEW.uom_code, NEW.product_id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER product_uom_conversions_guard BEFORE INSERT OR UPDATE ON product_uom_conversions
                FOR EACH ROW EXECUTE FUNCTION dgway_uom_conversion_guard();

            -- a factor is "base units per purchasing unit": changing the base unit would change its meaning
            CREATE OR REPLACE FUNCTION dgway_guard_base_uom_vs_conversions() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.base_uom_code IS DISTINCT FROM OLD.base_uom_code
                   AND EXISTS (SELECT 1 FROM product_uom_conversions WHERE product_id = NEW.id AND is_active) THEN
                    RAISE EXCEPTION 'PRODUCT_UOM_LOCKED: product % has active purchasing-unit conversions', NEW.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER products_base_uom_conversion_guard BEFORE UPDATE OF base_uom_code ON products
                FOR EACH ROW EXECUTE FUNCTION dgway_guard_base_uom_vs_conversions();
        SQL);

        // snapshot on PO lines (backfill: unit = base, factor = 1, price = unit cost)
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->string('purchase_uom_code', 16)->nullable();
            $t->decimal('purchase_quantity', 18, 4)->nullable();
            $t->decimal('purchase_unit_price', 19, 4)->nullable();
            $t->decimal('conversion_factor', 18, 6)->nullable();
        });
        DB::statement('UPDATE purchase_order_lines SET purchase_uom_code = uom_code, purchase_quantity = ordered_quantity, purchase_unit_price = unit_cost, conversion_factor = 1');
        DB::statement('ALTER TABLE purchase_order_lines ALTER COLUMN purchase_uom_code SET NOT NULL, ALTER COLUMN purchase_quantity SET NOT NULL,
            ALTER COLUMN purchase_unit_price SET NOT NULL, ALTER COLUMN conversion_factor SET NOT NULL');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_purchase_uom_foreign FOREIGN KEY (purchase_uom_code) REFERENCES uoms(code) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT pol_cost_check');
        DB::statement(self::unitCheck('purchase_order_lines', 'pol_unit_check', 'ordered_quantity', 'purchase_unit_price', 'line_total'));

        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->string('purchase_uom_code', 16)->nullable();
            $t->decimal('purchase_quantity', 18, 4)->nullable();
            $t->decimal('purchase_unit_cost', 19, 4)->nullable();
            $t->decimal('conversion_factor', 18, 6)->nullable();
            $t->index('purchase_order_line_id');
        });
        // goods_receipt_lines rows are immutable by trigger once their receipt left DRAFT: backfill with the guard disabled
        DB::statement('ALTER TABLE goods_receipt_lines DISABLE TRIGGER goods_receipt_lines_guard');
        DB::statement('UPDATE goods_receipt_lines SET purchase_uom_code = uom_code, purchase_quantity = received_quantity, purchase_unit_cost = unit_cost, conversion_factor = 1');
        DB::statement('ALTER TABLE goods_receipt_lines ENABLE TRIGGER goods_receipt_lines_guard');
        DB::statement('ALTER TABLE goods_receipt_lines ALTER COLUMN purchase_uom_code SET NOT NULL, ALTER COLUMN purchase_quantity SET NOT NULL,
            ALTER COLUMN purchase_unit_cost SET NOT NULL, ALTER COLUMN conversion_factor SET NOT NULL');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_purchase_uom_foreign FOREIGN KEY (purchase_uom_code) REFERENCES uoms(code) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE goods_receipt_lines DROP CONSTRAINT grl_cost_check');
        DB::statement(self::unitCheck('goods_receipt_lines', 'grl_unit_check', 'received_quantity', 'purchase_unit_cost', 'line_cost'));

        // ── 3. QUARANTINE disposition ────────────────────────────────────────
        DB::statement('ALTER TABLE goods_receipt_dispositions DROP CONSTRAINT grd_type_check');
        DB::statement("ALTER TABLE goods_receipt_dispositions ADD CONSTRAINT grd_type_check CHECK (disposition_type IN ('ACCEPT','QUARANTINE','LOSS','RETURN_TO_SUPPLIER','REGRADE'))");

        // ── 4. DRAFT documents are editable (new guard functions) ────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION dgway_po_line_guard_f4a() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE po_status text;
            BEGIN
                SELECT status INTO po_status FROM purchase_orders WHERE id = COALESCE(NEW.purchase_order_id, OLD.purchase_order_id);
                IF TG_OP = 'UPDATE' AND NEW.purchase_order_id IS DISTINCT FROM OLD.purchase_order_id THEN
                    RAISE EXCEPTION 'A purchase order line cannot move to another order' USING ERRCODE = 'restrict_violation';
                END IF;
                IF po_status = 'DRAFT' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;
                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION 'Lines can only be added while the purchase order is DRAFT (status %)', po_status USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Purchase order lines are never deleted after DRAFT' USING ERRCODE = 'restrict_violation';
                END IF;
                IF (NEW.line_no, NEW.product_id, NEW.uom_code, NEW.ordered_quantity, NEW.unit_cost, NEW.line_total,
                    NEW.purchase_uom_code, NEW.purchase_quantity, NEW.purchase_unit_price, NEW.conversion_factor)
                    IS DISTINCT FROM (OLD.line_no, OLD.product_id, OLD.uom_code, OLD.ordered_quantity, OLD.unit_cost, OLD.line_total,
                    OLD.purchase_uom_code, OLD.purchase_quantity, OLD.purchase_unit_price, OLD.conversion_factor) THEN
                    RAISE EXCEPTION 'Purchase order line terms are immutable after DRAFT; only received_quantity changes' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END; $$;
            DROP TRIGGER purchase_order_lines_guard ON purchase_order_lines;
            CREATE TRIGGER purchase_order_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_po_line_guard_f4a();

            CREATE OR REPLACE FUNCTION dgway_receipt_detail_guard_f4a() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE receipt_status text;
            BEGIN
                IF TG_TABLE_NAME = 'goods_receipt_lines' THEN
                    SELECT status INTO receipt_status FROM goods_receipts WHERE id = COALESCE(NEW.goods_receipt_id, OLD.goods_receipt_id);
                    IF TG_OP = 'UPDATE' AND NEW.goods_receipt_id IS DISTINCT FROM OLD.goods_receipt_id THEN
                        RAISE EXCEPTION 'A receipt line cannot move to another receipt' USING ERRCODE = 'restrict_violation';
                    END IF;
                ELSE
                    SELECT r.status INTO receipt_status FROM goods_receipts r JOIN goods_receipt_lines l ON l.goods_receipt_id = r.id
                     WHERE l.id = COALESCE(NEW.goods_receipt_line_id, OLD.goods_receipt_line_id);
                END IF;
                IF receipt_status IS DISTINCT FROM 'DRAFT' THEN
                    RAISE EXCEPTION 'Receipt details are immutable once the receipt left DRAFT; receipt status is % (%)', receipt_status, TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$;
            DROP TRIGGER goods_receipt_lines_guard ON goods_receipt_lines;
            CREATE TRIGGER goods_receipt_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard_f4a();
            DROP TRIGGER goods_receipt_dispositions_guard ON goods_receipt_dispositions;
            CREATE TRIGGER goods_receipt_dispositions_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_dispositions
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard_f4a();
        SQL);
    }

    public function down(): void
    {
        $closed = DB::table('purchase_orders')->where('status', 'CLOSED')->count();
        $quarantine = DB::table('goods_receipt_dispositions')->where('disposition_type', 'QUARANTINE')->count();
        $conversions = Schema::hasTable('product_uom_conversions') ? DB::table('product_uom_conversions')->count() : 0;
        $converted = DB::table('purchase_order_lines')->where('conversion_factor', '<>', 1)->count()
            + DB::table('goods_receipt_lines')->where('conversion_factor', '<>', 1)->count();
        if ($closed + $quarantine + $conversions + $converted > 0) {
            throw new RuntimeException(
                "Refusing to roll back F4A: {$closed} CLOSED purchase order(s), {$quarantine} QUARANTINE disposition(s), "
                ."{$conversions} unit conversion(s) and {$converted} line(s) bought in a purchasing unit exist. History is never destroyed by a rollback."
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER goods_receipt_dispositions_guard ON goods_receipt_dispositions;
            CREATE TRIGGER goods_receipt_dispositions_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_dispositions
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard();
            DROP TRIGGER goods_receipt_lines_guard ON goods_receipt_lines;
            CREATE TRIGGER goods_receipt_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard();
            DROP FUNCTION dgway_receipt_detail_guard_f4a();
            DROP TRIGGER purchase_order_lines_guard ON purchase_order_lines;
            CREATE TRIGGER purchase_order_lines_guard BEFORE UPDATE OR DELETE ON purchase_order_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_po_line_guard();
            DROP FUNCTION dgway_po_line_guard_f4a();
        SQL);

        DB::statement('ALTER TABLE goods_receipt_dispositions DROP CONSTRAINT grd_type_check');
        DB::statement("ALTER TABLE goods_receipt_dispositions ADD CONSTRAINT grd_type_check CHECK (disposition_type IN ('ACCEPT','LOSS','RETURN_TO_SUPPLIER','REGRADE'))");

        DB::statement('ALTER TABLE goods_receipt_lines DROP CONSTRAINT grl_unit_check');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_cost_check CHECK (unit_cost >= 0 AND line_cost = round(received_quantity * unit_cost, 4))');
        DB::statement('ALTER TABLE goods_receipt_lines DROP CONSTRAINT goods_receipt_lines_purchase_uom_foreign');
        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->dropIndex(['purchase_order_line_id']);
            $t->dropColumn(['purchase_uom_code', 'purchase_quantity', 'purchase_unit_cost', 'conversion_factor']);
        });

        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT pol_unit_check');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_cost_check CHECK (unit_cost >= 0 AND line_total = round(ordered_quantity * unit_cost, 4))');
        DB::statement('ALTER TABLE purchase_order_lines DROP CONSTRAINT purchase_order_lines_purchase_uom_foreign');
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->dropColumn(['purchase_uom_code', 'purchase_quantity', 'purchase_unit_price', 'conversion_factor']);
        });

        DB::unprepared('DROP TRIGGER IF EXISTS products_base_uom_conversion_guard ON products; DROP FUNCTION IF EXISTS dgway_guard_base_uom_vs_conversions();');
        Schema::dropIfExists('product_uom_conversions');
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_uom_conversion_guard();');

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_orders_guard ON purchase_orders; DROP FUNCTION IF EXISTS dgway_purchase_order_guard();');
        DB::statement('DROP INDEX IF EXISTS purchase_orders_open_idx');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT po_closed_check');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT po_cancelled_at_check');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT po_confirmed_at_check');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT po_status_check');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_status_check CHECK (status IN ('DRAFT','CONFIRMED','PARTIALLY_RECEIVED','RECEIVED','CANCELLED'))");
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropForeign(['confirmed_by']);
            $t->dropForeign(['cancelled_by']);
            $t->dropForeign(['closed_by']);
            $t->dropColumn(['confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'closed_at', 'closed_by', 'close_reason_code', 'close_note']);
        });
    }

    /**
     * The purchasing-unit snapshot of a line:
     *   base quantity = purchase quantity × factor (EXACT: conversions never round a quantity);
     *   line total    = round(purchase quantity × purchase price, 4) — the agreed/actual amount;
     *   unit_cost     = base unit cost, price ÷ factor rounded to 4 decimals. Checked as a bound
     *                   (|unit_cost × factor − price| ≤ factor × 0.00005) so the database never
     *                   re-divides and double-rounds.
     * For a line in the base unit (factor 1) this is exactly the A3 rule.
     */
    private static function unitCheck(string $table, string $name, string $baseQty, string $price, string $total): string
    {
        return "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (
            purchase_quantity > 0 AND conversion_factor > 0 AND {$price} >= 0 AND unit_cost >= 0
            AND {$baseQty} = purchase_quantity * conversion_factor
            AND {$total} = round(purchase_quantity * {$price}, 4)
            AND abs(unit_cost * conversion_factor - {$price}) <= conversion_factor * 0.00005
            AND (purchase_uom_code <> uom_code OR conversion_factor = 1))";
    }
};
