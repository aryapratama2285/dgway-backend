<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement documents: purchase orders and goods receipts (with
 * dispositions). Documents reference master data by id only.
 *
 * Database backstops (the domain enforces the same rules first):
 * - status values and allowed status transitions;
 * - money/quantity signs, line totals = round(qty × cost, 4);
 * - received ≤ ordered on PO lines (no silent over-receipt);
 * - receipt lines/dispositions can only be written while the receipt is DRAFT;
 * - MASS BALANCE: a receipt can only become POSTED if, for every line,
 *   Σ dispositions = received quantity.
 * - documents are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->date('business_date');
            $t->char('currency', 3);
            $t->string('status', 24);
            $t->text('notes')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->integer('version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'business_date']);
            $t->index(['supplier_id', 'business_date']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_status_check CHECK (status IN ('DRAFT','CONFIRMED','PARTIALLY_RECEIVED','RECEIVED','CANCELLED'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT po_cancel_reason_check CHECK ((status = 'CANCELLED') = (cancel_reason IS NOT NULL))");
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT po_version_check CHECK (version >= 1)');

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->smallInteger('line_no');
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('ordered_quantity', 18, 4);
            $t->decimal('received_quantity', 18, 4)->default(0);
            $t->decimal('unit_cost', 19, 4);
            $t->decimal('line_total', 19, 4);
            $t->timestampsTz();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->unique(['purchase_order_id', 'line_no']);
            $t->unique(['purchase_order_id', 'product_id']);
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_quantities_check CHECK (ordered_quantity > 0 AND received_quantity >= 0 AND received_quantity <= ordered_quantity)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_cost_check CHECK (unit_cost >= 0 AND line_total = round(ordered_quantity * unit_cost, 4))');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_line_no_check CHECK (line_no >= 1)');

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->foreignUuid('supplier_id')->constrained('business_partners')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $t->date('business_date');
            $t->timestampTz('received_at');
            $t->char('currency', 3);
            $t->string('status', 16);
            $t->text('notes')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->timestampTz('reversed_at')->nullable();
            $t->string('status_reason', 500)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->integer('version')->default(1);
            $t->timestampsTz();
            $t->index(['status', 'business_date']);
            $t->index(['supplier_id', 'business_date']);
            $t->index(['purchase_order_id']);
        });
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT gr_status_check CHECK (status IN ('DRAFT','POSTED','REVERSED','CANCELLED'))");
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT gr_posted_at_check CHECK ((status IN ('POSTED','REVERSED')) = (posted_at IS NOT NULL))");
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT gr_reversed_at_check CHECK ((status = 'REVERSED') = (reversed_at IS NOT NULL))");
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT gr_version_check CHECK (version >= 1)');

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $t->smallInteger('line_no');
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('received_quantity', 18, 4);
            $t->decimal('unit_cost', 19, 4);
            $t->decimal('line_cost', 19, 4);
            $t->foreignUuid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->unique(['goods_receipt_id', 'line_no']);
            $t->unique(['goods_receipt_id', 'product_id']);
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_quantity_check CHECK (received_quantity > 0)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_cost_check CHECK (unit_cost >= 0 AND line_cost = round(received_quantity * unit_cost, 4))');

        Schema::create('goods_receipt_dispositions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $t->string('disposition_type', 24);
            $t->decimal('quantity', 18, 4);
            $t->foreignUuid('target_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->string('reason', 500)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index('goods_receipt_line_id');
        });
        DB::statement("ALTER TABLE goods_receipt_dispositions ADD CONSTRAINT grd_type_check CHECK (disposition_type IN ('ACCEPT','LOSS','RETURN_TO_SUPPLIER','REGRADE'))");
        DB::statement('ALTER TABLE goods_receipt_dispositions ADD CONSTRAINT grd_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE goods_receipt_dispositions ADD CONSTRAINT grd_target_check CHECK ((disposition_type = 'REGRADE') = (target_product_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            -- Receipt lines and dispositions exist only as written while the receipt is DRAFT.
            CREATE OR REPLACE FUNCTION dgway_receipt_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE receipt_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Receipt details are never deleted (%)', TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_TABLE_NAME = 'goods_receipt_lines' THEN
                    SELECT status INTO receipt_status FROM goods_receipts WHERE id = NEW.goods_receipt_id;
                ELSE
                    SELECT r.status INTO receipt_status FROM goods_receipts r JOIN goods_receipt_lines l ON l.goods_receipt_id = r.id WHERE l.id = NEW.goods_receipt_line_id;
                END IF;
                IF TG_OP = 'UPDATE' OR receipt_status IS DISTINCT FROM 'DRAFT' THEN
                    RAISE EXCEPTION 'Receipt details are immutable once written; receipt status is % (%)', receipt_status, TG_TABLE_NAME USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END; $$;

            CREATE TRIGGER goods_receipt_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard();
            CREATE TRIGGER goods_receipt_dispositions_guard BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_dispositions
                FOR EACH ROW EXECUTE FUNCTION dgway_receipt_detail_guard();

            -- Status machine + mass balance at posting.
            CREATE OR REPLACE FUNCTION dgway_goods_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE bad_line record; line_count int;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Goods receipts are never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('POSTED','CANCELLED')) OR
                    (OLD.status = 'POSTED' AND NEW.status = 'REVERSED')) THEN
                    RAISE EXCEPTION 'Invalid goods receipt transition % -> %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'DRAFT' AND (NEW.supplier_id, NEW.node_id, NEW.purchase_order_id, NEW.business_date, NEW.currency, NEW.number)
                        IS DISTINCT FROM (OLD.supplier_id, OLD.node_id, OLD.purchase_order_id, OLD.business_date, OLD.currency, OLD.number) THEN
                    RAISE EXCEPTION 'Posted goods receipt header is immutable' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'DRAFT' AND NEW.status = 'POSTED' THEN
                    SELECT count(*) INTO line_count FROM goods_receipt_lines WHERE goods_receipt_id = NEW.id;
                    IF line_count = 0 THEN
                        RAISE EXCEPTION 'MASS_BALANCE: receipt % has no lines', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                    SELECT l.line_no, l.received_quantity, COALESCE(sum(d.quantity), 0) AS disposed INTO bad_line
                      FROM goods_receipt_lines l LEFT JOIN goods_receipt_dispositions d ON d.goods_receipt_line_id = l.id
                     WHERE l.goods_receipt_id = NEW.id
                     GROUP BY l.id, l.line_no, l.received_quantity
                    HAVING COALESCE(sum(d.quantity), 0) <> l.received_quantity
                     LIMIT 1;
                    IF FOUND THEN
                        RAISE EXCEPTION 'MASS_BALANCE: receipt % line % received % but dispositions total %',
                            NEW.number, bad_line.line_no, bad_line.received_quantity, bad_line.disposed USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;

            CREATE TRIGGER goods_receipts_guard BEFORE UPDATE OR DELETE ON goods_receipts
                FOR EACH ROW EXECUTE FUNCTION dgway_goods_receipt_guard();

            -- PO lines: only received_quantity may change; never deleted.
            CREATE OR REPLACE FUNCTION dgway_po_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Purchase order lines are never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF (NEW.purchase_order_id, NEW.line_no, NEW.product_id, NEW.uom_code, NEW.ordered_quantity, NEW.unit_cost, NEW.line_total)
                    IS DISTINCT FROM (OLD.purchase_order_id, OLD.line_no, OLD.product_id, OLD.uom_code, OLD.ordered_quantity, OLD.unit_cost, OLD.line_total) THEN
                    RAISE EXCEPTION 'Purchase order line terms are immutable; only received_quantity changes' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END; $$;

            CREATE TRIGGER purchase_order_lines_guard BEFORE UPDATE OR DELETE ON purchase_order_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_po_line_guard();
            CREATE TRIGGER purchase_orders_no_delete BEFORE DELETE ON purchase_orders
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_dispositions');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_receipt_detail_guard() CASCADE; DROP FUNCTION IF EXISTS dgway_goods_receipt_guard() CASCADE; DROP FUNCTION IF EXISTS dgway_po_line_guard() CASCADE;');
    }
};
