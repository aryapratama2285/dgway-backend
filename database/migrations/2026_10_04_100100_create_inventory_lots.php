<?php

use App\Domain\Shared\Document\DocumentNumber;
use App\Domain\Shared\Time\BusinessDate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A5-B — inventory lots (batch traceability) as a DIMENSION of the A3
 * ledger, not a second source of truth.
 *
 * inventory_lots       immutable lot identity + provenance (append-only).
 *                      origin RECEIPT     one lot per posted goods-receipt line
 *                             REGRADE     derived lot, parent_lot_id = lot it came from
 *                             ADJUSTMENT  stock that appeared through a stock-count adjustment
 *                             LEGACY      pre-A5-B stock of one product/node, unattributed provenance
 * stock_movements      + lot_id. Every movement written after this migration
 *                      must name its lot (CHECK on created_at cut-over); rows
 *                      written before keep lot_id NULL forever (append-only)
 *                      and belong to the LEGACY lot of their product/node.
 * stock_lot_balances   projection per (lot, node, bucket), maintained in the
 *                      same transaction as the movements, rebuildable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cutover = DB::selectOne('SELECT clock_timestamp() AS t')->t;

        Schema::create('inventory_lots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 40)->unique();
            $t->string('origin', 16);
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('original_quantity', 18, 4)->nullable();
            $t->uuid('parent_lot_id')->nullable();
            $t->foreignUuid('supplier_id')->nullable()->constrained('business_partners')->restrictOnDelete();
            $t->foreignUuid('goods_receipt_id')->nullable()->constrained('goods_receipts')->restrictOnDelete();
            $t->foreignUuid('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();
            $t->string('source_document_type', 40);
            $t->uuid('source_document_id')->nullable();
            $t->date('business_date');
            $t->timestampTz('received_at', 6);
            $t->date('manufactured_date')->nullable();
            $t->date('best_before_date')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6)->useCurrent();
            $t->foreign('uom_code')->references('code')->on('uoms')->restrictOnDelete();
            $t->index(['product_id', 'node_id', 'business_date']);
            $t->index('supplier_id');
            $t->index('goods_receipt_id');
            $t->index('parent_lot_id');
            $t->index('business_date');
        });
        Schema::table('inventory_lots', fn (Blueprint $t) => $t->foreign('parent_lot_id')->references('id')->on('inventory_lots')->restrictOnDelete());
        DB::statement("ALTER TABLE inventory_lots ADD CONSTRAINT lot_origin_check CHECK (origin IN ('RECEIPT','REGRADE','ADJUSTMENT','LEGACY'))");
        DB::statement("ALTER TABLE inventory_lots ADD CONSTRAINT lot_quantity_check CHECK ((origin = 'LEGACY') = (original_quantity IS NULL) AND (original_quantity IS NULL OR original_quantity > 0))");
        DB::statement("ALTER TABLE inventory_lots ADD CONSTRAINT lot_provenance_check CHECK (
            (origin = 'RECEIPT' AND supplier_id IS NOT NULL AND goods_receipt_id IS NOT NULL AND goods_receipt_line_id IS NOT NULL
                AND source_document_type = 'goods_receipt' AND source_document_id = goods_receipt_id AND parent_lot_id IS NULL)
            OR (origin = 'REGRADE' AND parent_lot_id IS NOT NULL AND source_document_id IS NOT NULL
                AND source_document_type IN ('goods_receipt','inventory_operation'))
            OR (origin = 'ADJUSTMENT' AND parent_lot_id IS NULL AND supplier_id IS NULL AND goods_receipt_id IS NULL
                AND source_document_type = 'inventory_operation' AND source_document_id IS NOT NULL)
            OR (origin = 'LEGACY' AND parent_lot_id IS NULL AND supplier_id IS NULL AND goods_receipt_id IS NULL AND goods_receipt_line_id IS NULL
                AND source_document_type = 'legacy_backfill' AND source_document_id IS NULL))");
        DB::statement('ALTER TABLE inventory_lots ADD CONSTRAINT lot_parent_check CHECK (parent_lot_id IS NULL OR parent_lot_id <> id)');
        DB::statement('ALTER TABLE inventory_lots ADD CONSTRAINT lot_dates_check CHECK (
            (manufactured_date IS NULL OR manufactured_date <= business_date)
            AND (best_before_date IS NULL OR manufactured_date IS NULL OR best_before_date >= manufactured_date))');
        DB::statement("CREATE UNIQUE INDEX lot_one_per_receipt_line ON inventory_lots (goods_receipt_line_id) WHERE origin = 'RECEIPT'");
        DB::statement("CREATE UNIQUE INDEX lot_one_derived_per_source ON inventory_lots (parent_lot_id, source_document_id, product_id) WHERE origin = 'REGRADE'");
        DB::statement("CREATE UNIQUE INDEX lot_one_adjustment_per_operation ON inventory_lots (source_document_id) WHERE origin = 'ADJUSTMENT'");
        DB::statement("CREATE UNIQUE INDEX lot_one_legacy_per_position ON inventory_lots (product_id, node_id) WHERE origin = 'LEGACY'");

        // Optional dates captured at receiving and copied to the lot (never invented).
        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->date('manufactured_date')->nullable();
            $t->date('best_before_date')->nullable();
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_dates_check CHECK (best_before_date IS NULL OR manufactured_date IS NULL OR best_before_date >= manufactured_date)');

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->uuid('lot_id')->nullable();
            $t->foreign('lot_id')->references('id')->on('inventory_lots')->restrictOnDelete();
            $t->index(['lot_id', 'sequence']);
        });
        // Every movement from now on names its lot; historical rows (created before the cut-over) cannot be edited.
        // The cut-over instant lives in an IMMUTABLE function (it never changes
        // after this migration ran), so the constraint text is identical in every
        // environment and the generated schema catalog does not depend on when
        // a database was migrated.
        DB::unprepared("CREATE OR REPLACE FUNCTION dgway_lot_cutover() RETURNS timestamptz LANGUAGE sql IMMUTABLE AS \$\$ SELECT '{$cutover}'::timestamptz \$\$");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT sm_lot_required_check CHECK (lot_id IS NOT NULL OR created_at < dgway_lot_cutover())');

        Schema::create('stock_lot_balances', function (Blueprint $t) {
            $t->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $t->foreignUuid('node_id')->constrained('nodes')->restrictOnDelete();
            $t->string('bucket', 16);
            $t->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $t->string('uom_code', 16);
            $t->decimal('quantity', 18, 4)->default(0);
            $t->timestampTz('updated_at')->useCurrent();
            $t->primary(['lot_id', 'node_id', 'bucket']);
            $t->index(['product_id', 'node_id', 'bucket']);
        });
        DB::statement("ALTER TABLE stock_lot_balances ADD CONSTRAINT slb_bucket_check CHECK (bucket IN ('AVAILABLE','QUARANTINE','RETURN_PENDING'))");
        DB::statement('ALTER TABLE stock_lot_balances ADD CONSTRAINT slb_non_negative_check CHECK (quantity >= 0)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER inventory_lots_append_only BEFORE UPDATE OR DELETE ON inventory_lots
                FOR EACH ROW EXECUTE FUNCTION dgway_forbid_mutation();

            -- The legacy lot that stands for a product/node's pre-A5-B (lot_id NULL) movements.
            CREATE OR REPLACE FUNCTION dgway_legacy_lot(p uuid, n uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
                SELECT id FROM inventory_lots WHERE origin = 'LEGACY' AND product_id = p AND node_id = n
            $$;

            -- Lot provenance must match what it claims.
            CREATE OR REPLACE FUNCTION dgway_lot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r record; parent record;
            BEGIN
                IF NEW.origin = 'RECEIPT' THEN
                    SELECT g.supplier_id, g.node_id, g.status, l.product_id, l.uom_code INTO r
                      FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id
                     WHERE l.id = NEW.goods_receipt_line_id AND g.id = NEW.goods_receipt_id;
                    IF NOT FOUND OR r.product_id <> NEW.product_id OR r.supplier_id <> NEW.supplier_id OR r.node_id <> NEW.node_id OR r.uom_code <> NEW.uom_code THEN
                        RAISE EXCEPTION 'LOT_PROVENANCE: lot % does not match its goods receipt line', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.origin = 'REGRADE' THEN
                    SELECT product_id INTO parent FROM inventory_lots WHERE id = NEW.parent_lot_id;
                    IF parent.product_id = NEW.product_id THEN
                        RAISE EXCEPTION 'LOT_LINEAGE: a derived lot must change the product (lot %)', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER inventory_lots_guard BEFORE INSERT ON inventory_lots FOR EACH ROW EXECUTE FUNCTION dgway_lot_guard();

            -- Movement ↔ lot consistency.
            CREATE OR REPLACE FUNCTION dgway_movement_lot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE lot record;
            BEGIN
                IF NEW.lot_id IS NULL THEN
                    RETURN NEW; -- only possible for pre-cut-over rows (CHECK sm_lot_required_check)
                END IF;
                SELECT product_id, origin, source_document_id, uom_code INTO lot FROM inventory_lots WHERE id = NEW.lot_id;
                IF lot.product_id <> NEW.product_id OR lot.uom_code <> NEW.uom_code THEN
                    RAISE EXCEPTION 'LOT_PRODUCT_MISMATCH: movement for product % cannot use lot of product %', NEW.product_id, lot.product_id USING ERRCODE = 'check_violation';
                END IF;
                -- Quantity is created in a lot only by the document that created the lot.
                IF (NEW.movement_type = 'RECEIPT' AND (lot.origin <> 'RECEIPT' OR lot.source_document_id <> NEW.source_document_id))
                   OR (NEW.movement_type = 'REGRADE_IN' AND (lot.origin <> 'REGRADE' OR lot.source_document_id <> NEW.source_document_id))
                   OR (NEW.movement_type = 'ADJUSTMENT_IN' AND (lot.origin <> 'ADJUSTMENT' OR lot.source_document_id <> NEW.source_document_id)) THEN
                    RAISE EXCEPTION 'LOT_SOURCE_MISMATCH: % movement cannot create quantity in lot % (origin %)', NEW.movement_type, NEW.lot_id, lot.origin USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;
            CREATE TRIGGER stock_movements_lot_guard BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION dgway_movement_lot_guard();

            -- Reversal mirrors its original including the lot (legacy rows map to their legacy lot).
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
                    IS DISTINCT FROM (o.product_id, o.node_id, o.bucket, o.quantity, o.uom_code, -o.direction)
                   OR NEW.lot_id IS DISTINCT FROM COALESCE(o.lot_id, dgway_legacy_lot(o.product_id, o.node_id)) THEN
                    RAISE EXCEPTION 'Reversal must mirror movement % (same position, lot and quantity, opposite direction)', o.id USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END; $$;

            -- Original quantity of a non-legacy lot = Σ of the movements that created it (checked at COMMIT).
            CREATE OR REPLACE FUNCTION dgway_check_lot_origin_quantity() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE lid uuid; lot record; created numeric;
            BEGIN
                IF TG_TABLE_NAME = 'inventory_lots' THEN
                    lid := NEW.id;
                ELSE
                    lid := NEW.lot_id;
                END IF;
                IF lid IS NULL THEN RETURN NULL; END IF;
                SELECT origin, original_quantity, number INTO lot FROM inventory_lots WHERE id = lid;
                IF lot.origin = 'LEGACY' THEN RETURN NULL; END IF;
                SELECT COALESCE(sum(quantity), 0) INTO created FROM stock_movements
                 WHERE lot_id = lid AND movement_type = CASE lot.origin WHEN 'RECEIPT' THEN 'RECEIPT' WHEN 'REGRADE' THEN 'REGRADE_IN' ELSE 'ADJUSTMENT_IN' END;
                IF created <> lot.original_quantity THEN
                    RAISE EXCEPTION 'LOT_ORIGINAL_QUANTITY: lot % original % but its creating movements total %', lot.number, lot.original_quantity, created
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END; $$;
            CREATE CONSTRAINT TRIGGER inventory_lots_origin_quantity AFTER INSERT ON inventory_lots
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION dgway_check_lot_origin_quantity();
            CREATE CONSTRAINT TRIGGER stock_movements_lot_origin_quantity AFTER INSERT ON stock_movements
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                WHEN (NEW.movement_type IN ('RECEIPT','REGRADE_IN','ADJUSTMENT_IN'))
                EXECUTE FUNCTION dgway_check_lot_origin_quantity();
        SQL);

        $this->backfillLegacyLots();
    }

    /**
     * Pre-A5-B stock has no known lot. One LEGACY lot per product/node stands
     * for it (no supplier, no receipt: provenance is NOT invented). Its
     * movements are the historical rows with lot_id NULL, which stay untouched.
     */
    private function backfillLegacyLots(): void
    {
        $positions = DB::select('SELECT product_id, node_id, min(uom_code) AS uom_code, min(business_date) AS first_date, min(occurred_at) AS first_at
                                   FROM stock_movements WHERE lot_id IS NULL GROUP BY product_id, node_id ORDER BY product_id, node_id');
        foreach ($positions as $p) {
            // Deterministic identity: the same history always yields the same
            // legacy lot (UUID from product+node, number dated at the first
            // known movement), so a dev rollback + re-migrate reconciles exactly.
            $date = BusinessDate::fromString((string) $p->first_date);
            $seq = DB::selectOne(
                "INSERT INTO document_sequences (prefix, sequence_date, last_value, created_at, updated_at) VALUES ('LOT', ?, 1, now(), now())
                 ON CONFLICT (prefix, sequence_date) DO UPDATE SET last_value = document_sequences.last_value + 1, updated_at = now()
                 RETURNING last_value", [$date->toString()])->last_value;
            $h = md5("dgway-legacy-lot|{$p->product_id}|{$p->node_id}");
            DB::table('inventory_lots')->insert([
                'id' => sprintf('%s-%s-8%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)).substr($h, 17, 3), substr($h, 20, 12)),
                'number' => DocumentNumber::compose('LOT', $date, (int) $seq)->toString(),
                'origin' => 'LEGACY',
                'product_id' => $p->product_id,
                'node_id' => $p->node_id,
                'uom_code' => $p->uom_code,
                'source_document_type' => 'legacy_backfill',
                'business_date' => $p->first_date,
                'received_at' => $p->first_at,
            ]);
        }

        DB::statement(<<<'SQL'
            INSERT INTO stock_lot_balances (lot_id, node_id, bucket, product_id, uom_code, quantity, updated_at)
            SELECT COALESCE(m.lot_id, dgway_legacy_lot(m.product_id, m.node_id)), m.node_id, m.bucket, m.product_id, min(m.uom_code), sum(m.signed_quantity), now()
              FROM stock_movements m GROUP BY 1, 2, 3, 4
        SQL);
    }

    public function down(): void
    {
        // Development only (destructive commands are prohibited in production).
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_movements_lot_origin_quantity ON stock_movements;
            DROP TRIGGER IF EXISTS stock_movements_lot_guard ON stock_movements;
            ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS sm_lot_required_check;
            DROP FUNCTION IF EXISTS dgway_lot_cutover();
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
        SQL);
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropForeign(['lot_id']);
            $t->dropIndex(['lot_id', 'sequence']);
            $t->dropColumn('lot_id');
        });
        Schema::dropIfExists('stock_lot_balances');
        Schema::dropIfExists('inventory_lots');
        DB::statement('ALTER TABLE goods_receipt_lines DROP CONSTRAINT IF EXISTS grl_dates_check');
        Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn(['manufactured_date', 'best_before_date']));
        DB::unprepared("DROP FUNCTION IF EXISTS dgway_lot_guard() CASCADE; DROP FUNCTION IF EXISTS dgway_movement_lot_guard() CASCADE;
            DROP FUNCTION IF EXISTS dgway_check_lot_origin_quantity() CASCADE; DROP FUNCTION IF EXISTS dgway_legacy_lot(uuid, uuid);
            DELETE FROM document_sequences WHERE prefix = 'LOT';");
    }
};
