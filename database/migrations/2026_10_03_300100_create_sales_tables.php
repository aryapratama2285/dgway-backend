<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A4 — sales, payments, customer returns.
 *
 * Money truth:
 *   payments / payment_reversals / customer_returns(+lines) are APPEND-ONLY
 *   financial history (UPDATE/DELETE rejected by trigger).
 *   sales.paid_amount, sales.credited_amount and sale_lines.returned_* are
 *   cached sums of that history, maintained in the same transaction under a
 *   row lock on the sale. A DEFERRED constraint trigger re-computes the sums
 *   at COMMIT and rejects the transaction if any cache disagrees with the
 *   history — so the cache cannot drift, not even through raw SQL.
 *   sales.outstanding is a GENERATED column (grand_total − paid − credited
 *   for final sales, 0 otherwise) with CHECK ≥ 0 → overpayment impossible.
 */
return new class extends Migration
{
    private const SALE_STATUSES = "'DRAFT','CONFIRMED','COMPLETED','CANCELLED','REVERSED'";
    private const METHODS = "'CASH','BANK_TRANSFER','QRIS'";

    public function up(): void
    {
        Schema::create('sales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('version');
            $t->string('number', 40)->unique();
            $t->string('origin', 16)->default('DIRECT');
            $t->uuid('order_reference_id')->nullable();
            $t->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->date('business_date');
            $t->char('currency', 3);
            $t->string('status', 16);
            $t->decimal('subtotal', 19, 4);
            $t->decimal('discount_total', 19, 4)->default(0);
            $t->decimal('grand_total', 19, 4);
            $t->decimal('paid_amount', 19, 4)->default(0);
            $t->decimal('credited_amount', 19, 4)->default(0);
            $t->smallInteger('payment_term_days')->nullable();
            $t->date('due_date')->nullable();
            $t->timestampTz('confirmed_at', 6)->nullable();
            $t->timestampTz('completed_at', 6)->nullable();
            $t->timestampTz('reversed_at', 6)->nullable();
            $t->string('status_reason', 500)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz(6);
            $t->index(['business_date', 'status']);
            $t->index(['customer_id', 'business_date']);
            $t->index('node_id');
        });
        DB::statement("ALTER TABLE sales ADD COLUMN outstanding numeric(19,4) GENERATED ALWAYS AS (
            CASE WHEN status IN ('CONFIRMED','COMPLETED') THEN grand_total - paid_amount - credited_amount ELSE 0 END) STORED");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('.self::SALE_STATUSES.'))');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_origin_check CHECK (origin IN ('DIRECT','ORDER') AND (origin = 'ORDER') = (order_reference_id IS NOT NULL))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal >= 0 AND discount_total >= 0 AND paid_amount >= 0 AND credited_amount >= 0
            AND grand_total = subtotal - discount_total AND grand_total >= 0)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_outstanding_check CHECK (outstanding >= 0)');
        // Money moves only on final sales; a reversed sale keeps no money.
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_money_status_check CHECK (
            (status IN ('CONFIRMED','COMPLETED')) OR (paid_amount = 0 AND credited_amount = 0))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_completed_check CHECK (
            (status = 'COMPLETED') <= (grand_total - paid_amount - credited_amount = 0)
            AND (status = 'CONFIRMED') <= (grand_total - paid_amount - credited_amount > 0))");
        // Walk-in (no customer) never owes money.
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_walk_in_cash_check CHECK (
            customer_id IS NOT NULL OR status NOT IN ('CONFIRMED','COMPLETED') OR grand_total - paid_amount - credited_amount = 0)");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_confirmation_check CHECK (
            (status IN ('DRAFT','CANCELLED')) = (confirmed_at IS NULL)
            AND (confirmed_at IS NULL) = (due_date IS NULL) AND (confirmed_at IS NULL) = (payment_term_days IS NULL))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_reversed_check CHECK ((status = 'REVERSED') = (reversed_at IS NOT NULL))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_due_date_check CHECK (due_date IS NULL OR due_date >= business_date)');
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_version_check CHECK (version >= 1)');
        // Receivable look-ups only ever scan open sales.
        DB::statement('CREATE INDEX sales_open_customer_idx ON sales (customer_id, due_date) WHERE outstanding > 0');
        DB::statement('CREATE INDEX sales_open_due_idx ON sales (due_date) WHERE outstanding > 0');

        Schema::create('sale_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $t->smallInteger('line_no');
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_price', 19, 4);
            $t->decimal('line_subtotal', 19, 4);
            $t->decimal('discount_amount', 19, 4)->default(0);
            $t->decimal('line_total', 19, 4);
            $t->decimal('returned_quantity', 18, 4)->default(0);
            $t->decimal('returned_amount', 19, 4)->default(0);
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->unique(['sale_id', 'line_no']);
            $t->index('product_id');
        });
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sl_quantity_check CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sl_price_check CHECK (unit_price >= 0 AND line_subtotal = round(quantity * unit_price, 4)
            AND discount_amount >= 0 AND discount_amount <= line_subtotal AND line_total = line_subtotal - discount_amount)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sl_returned_amount_check CHECK (returned_amount >= 0 AND returned_amount <= line_total)');
        DB::statement('ALTER TABLE sale_lines ADD CONSTRAINT sl_line_no_check CHECK (line_no >= 1)');

        Schema::create('payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $t->string('method', 24);
            $t->decimal('amount', 19, 4);
            $t->char('currency', 3);
            $t->timestampTz('paid_at', 6);
            $t->date('business_date');
            $t->string('reference', 100)->nullable();
            $t->string('notes', 500)->nullable();
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index('sale_id');
            $t->index(['business_date', 'method']);
            $t->index('customer_id');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_method_check CHECK (method IN ('.self::METHODS.'))');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_amount_check CHECK (amount > 0)');

        Schema::create('payment_reversals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $t->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $t->string('reason', 500);
            $t->timestampTz('reversed_at', 6);
            $t->date('business_date');
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index('sale_id');
            $t->index('business_date');
        });
        DB::statement('ALTER TABLE payment_reversals ADD CONSTRAINT payrev_reason_check CHECK (length(trim(reason)) > 0)');

        Schema::create('customer_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->date('business_date');
            $t->timestampTz('returned_at', 6);
            $t->char('currency', 3);
            $t->decimal('total_value', 19, 4);
            $t->decimal('credit_amount', 19, 4);
            $t->decimal('refund_amount', 19, 4);
            $t->string('refund_method', 24)->nullable();
            $t->string('reason', 500);
            $t->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->index('sale_id');
            $t->index('business_date');
        });
        DB::statement('ALTER TABLE customer_returns ADD CONSTRAINT cr_amounts_check CHECK (total_value >= 0 AND credit_amount >= 0 AND refund_amount >= 0
            AND total_value = credit_amount + refund_amount)');
        DB::statement('ALTER TABLE customer_returns ADD CONSTRAINT cr_refund_method_check CHECK ((refund_amount > 0) = (refund_method IS NOT NULL)
            AND (refund_method IS NULL OR refund_method IN ('.self::METHODS.')))');
        DB::statement('ALTER TABLE customer_returns ADD CONSTRAINT cr_reason_check CHECK (length(trim(reason)) > 0)');

        Schema::create('customer_return_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('customer_return_id')->constrained('customer_returns')->restrictOnDelete();
            $t->foreignUuid('sale_line_id')->constrained('sale_lines')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('quantity', 18, 4);
            $t->decimal('value', 19, 4);
            $t->string('disposition', 24);
            $t->string('reason', 500)->nullable();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->index('customer_return_id');
            $t->index('sale_line_id');
            $t->index('product_id');
        });
        DB::statement("ALTER TABLE customer_return_lines ADD CONSTRAINT crl_check CHECK (quantity > 0 AND value >= 0
            AND disposition IN ('RETURN_TO_AVAILABLE','RETURN_TO_QUARANTINE','LOSS'))");

        DB::unprepared(<<<'SQL'
            -- Financial history is append-only.
            CREATE TRIGGER payments_append_only BEFORE UPDATE OR DELETE ON payments
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            CREATE TRIGGER payment_reversals_append_only BEFORE UPDATE OR DELETE ON payment_reversals
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            CREATE TRIGGER customer_returns_append_only BEFORE UPDATE OR DELETE ON customer_returns
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();
            CREATE TRIGGER customer_return_lines_append_only BEFORE UPDATE OR DELETE ON customer_return_lines
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();

            -- Sale header: status machine, no delete, final header immutable, totals = Σ lines when leaving DRAFT.
            CREATE OR REPLACE FUNCTION dgway_sale_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s record;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Sales are never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('CONFIRMED','COMPLETED','CANCELLED')) OR
                    (OLD.status = 'CONFIRMED' AND NEW.status IN ('COMPLETED','REVERSED')) OR
                    (OLD.status = 'COMPLETED' AND NEW.status IN ('CONFIRMED','REVERSED'))) THEN
                    RAISE EXCEPTION 'Invalid sale transition % -> %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'DRAFT' AND (NEW.number, NEW.origin, NEW.order_reference_id, NEW.customer_id, NEW.node_id, NEW.business_date,
                        NEW.currency, NEW.subtotal, NEW.discount_total, NEW.grand_total, NEW.payment_term_days, NEW.due_date, NEW.confirmed_at)
                    IS DISTINCT FROM (OLD.number, OLD.origin, OLD.order_reference_id, OLD.customer_id, OLD.node_id, OLD.business_date,
                        OLD.currency, OLD.subtotal, OLD.discount_total, OLD.grand_total, OLD.payment_term_days, OLD.due_date, OLD.confirmed_at) THEN
                    RAISE EXCEPTION 'A confirmed sale is immutable; use reversal / return' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.status = 'DRAFT' AND NEW.status IN ('CONFIRMED','COMPLETED') THEN
                    SELECT count(*) AS n, COALESCE(sum(line_subtotal), 0) AS sub, COALESCE(sum(discount_amount), 0) AS disc
                      INTO s FROM sale_lines WHERE sale_id = NEW.id;
                    IF s.n = 0 OR s.sub <> NEW.subtotal OR s.disc <> NEW.discount_total THEN
                        RAISE EXCEPTION 'SALE_TOTALS: sale % header totals do not match its lines', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER sales_guard BEFORE UPDATE OR DELETE ON sales FOR EACH ROW EXECUTE FUNCTION dgway_sale_guard();

            -- Sale lines: inserted only while the sale is DRAFT; afterwards only the
            -- return counters may change, and only upwards. Never deleted.
            CREATE OR REPLACE FUNCTION dgway_sale_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE sale_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Sale lines are never deleted' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT status INTO sale_status FROM sales WHERE id = NEW.sale_id;
                    IF sale_status IS DISTINCT FROM 'DRAFT' THEN
                        RAISE EXCEPTION 'Lines can only be added to a DRAFT sale' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END IF;
                IF (NEW.sale_id, NEW.line_no, NEW.product_id, NEW.uom_code, NEW.quantity, NEW.unit_price, NEW.line_subtotal, NEW.discount_amount, NEW.line_total)
                    IS DISTINCT FROM (OLD.sale_id, OLD.line_no, OLD.product_id, OLD.uom_code, OLD.quantity, OLD.unit_price, OLD.line_subtotal, OLD.discount_amount, OLD.line_total)
                   OR NEW.returned_quantity < OLD.returned_quantity OR NEW.returned_amount < OLD.returned_amount THEN
                    RAISE EXCEPTION 'Sale line terms are immutable; only return counters grow' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER sale_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON sale_lines FOR EACH ROW EXECUTE FUNCTION dgway_sale_line_guard();

            -- Cached sums must equal the append-only history, checked at COMMIT.
            CREATE OR REPLACE FUNCTION dgway_check_sale_money() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE sid uuid; s record; paid numeric; credited numeric; bad record;
            BEGIN
                IF TG_TABLE_NAME = 'sales' THEN
                    sid := NEW.id;
                ELSIF TG_TABLE_NAME = 'customer_return_lines' THEN
                    SELECT sale_id INTO sid FROM customer_returns WHERE id = NEW.customer_return_id;
                ELSE
                    sid := NEW.sale_id;
                END IF;
                SELECT paid_amount, credited_amount, number INTO s FROM sales WHERE id = sid;
                SELECT COALESCE(sum(p.amount), 0) INTO paid FROM payments p
                 WHERE p.sale_id = sid AND NOT EXISTS (SELECT 1 FROM payment_reversals r WHERE r.payment_id = p.id);
                SELECT COALESCE(sum(credit_amount), 0) INTO credited FROM customer_returns WHERE sale_id = sid;
                IF s.paid_amount <> paid OR s.credited_amount <> credited THEN
                    RAISE EXCEPTION 'SALE_MONEY: sale % caches paid=% credited=% but history says paid=% credited=%',
                        s.number, s.paid_amount, s.credited_amount, paid, credited USING ERRCODE = 'check_violation';
                END IF;
                SELECT l.line_no INTO bad FROM sale_lines l
                  LEFT JOIN (SELECT sale_line_id, sum(quantity) q, sum(value) v FROM customer_return_lines GROUP BY sale_line_id) r ON r.sale_line_id = l.id
                 WHERE l.sale_id = sid AND (l.returned_quantity <> COALESCE(r.q, 0) OR l.returned_amount <> COALESCE(r.v, 0)) LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION 'SALE_MONEY: sale % line % return counters disagree with return history', s.number, bad.line_no USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER sales_money_check AFTER INSERT OR UPDATE ON sales
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
            CREATE CONSTRAINT TRIGGER sale_lines_money_check AFTER UPDATE ON sale_lines
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
            CREATE CONSTRAINT TRIGGER payments_money_check AFTER INSERT ON payments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
            CREATE CONSTRAINT TRIGGER payment_reversals_money_check AFTER INSERT ON payment_reversals
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
            CREATE CONSTRAINT TRIGGER customer_returns_money_check AFTER INSERT ON customer_returns
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
            CREATE CONSTRAINT TRIGGER customer_return_lines_money_check AFTER INSERT ON customer_return_lines
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_sale_money();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_return_lines');
        Schema::dropIfExists('customer_returns');
        Schema::dropIfExists('payment_reversals');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        DB::unprepared('DROP FUNCTION IF EXISTS dgway_sale_guard() CASCADE; DROP FUNCTION IF EXISTS dgway_sale_line_guard() CASCADE; DROP FUNCTION IF EXISTS dgway_check_sale_money() CASCADE;');
    }
};
