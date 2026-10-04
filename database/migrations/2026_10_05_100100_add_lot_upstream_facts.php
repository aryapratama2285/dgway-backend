<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase A5-C — upstream provenance refinement (addendum §1).
 *
 * A DG Way InventoryLot is DG WAY's internal lot, NOT a supplier production
 * batch. Two OPTIONAL upstream facts are added, on the receipt line (where
 * the operator records them) and on the lot (copied at posting):
 *
 *   collection_date     date the supplier/farm states the goods were collected
 *                       (eggs: collected / separated). Not a production date,
 *                       not the received date. Unknown = NULL.
 *   supplier_reference  the supplier's OWN batch/reference text, only if given.
 *
 * Additive and safe: existing rows keep NULL. Nothing is backfilled from the
 * receipt date, created_at, received_at or movement dates (historical
 * uncertainty stays explicit).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE goods_receipt_lines ADD COLUMN collection_date date NULL, ADD COLUMN supplier_reference varchar(60) NULL;
            ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_upstream_check CHECK (
                (supplier_reference IS NULL OR btrim(supplier_reference) <> '')
                AND (collection_date IS NULL OR manufactured_date IS NULL OR collection_date >= manufactured_date));

            ALTER TABLE inventory_lots ADD COLUMN collection_date date NULL, ADD COLUMN supplier_reference varchar(60) NULL;
            -- collected upstream on or before DG Way received it; never before a stated manufacture date;
            -- lots without a supplier origin (ADJUSTMENT, LEGACY) carry no upstream facts at all
            ALTER TABLE inventory_lots ADD CONSTRAINT lot_upstream_check CHECK (
                (collection_date IS NULL OR collection_date <= business_date)
                AND (collection_date IS NULL OR manufactured_date IS NULL OR collection_date >= manufactured_date)
                AND (supplier_reference IS NULL OR btrim(supplier_reference) <> '')
                AND (origin IN ('RECEIPT', 'REGRADE') OR (collection_date IS NULL AND supplier_reference IS NULL)));

            -- A5-B guard + the upstream facts of a lot must be exactly those recorded on its receipt line
            -- (RECEIPT) or inherited from its parent (REGRADE): the database refuses invented provenance.
            CREATE OR REPLACE FUNCTION dgway_lot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE r record; parent record;
            BEGIN
                IF NEW.origin = 'RECEIPT' THEN
                    SELECT g.supplier_id, g.node_id, g.status, l.product_id, l.uom_code, l.collection_date, l.supplier_reference INTO r
                      FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id
                     WHERE l.id = NEW.goods_receipt_line_id AND g.id = NEW.goods_receipt_id;
                    IF NOT FOUND OR r.product_id <> NEW.product_id OR r.supplier_id <> NEW.supplier_id OR r.node_id <> NEW.node_id OR r.uom_code <> NEW.uom_code THEN
                        RAISE EXCEPTION 'LOT_PROVENANCE: lot % does not match its goods receipt line', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.collection_date IS DISTINCT FROM r.collection_date OR NEW.supplier_reference IS DISTINCT FROM r.supplier_reference THEN
                        RAISE EXCEPTION 'LOT_UPSTREAM_MISMATCH: lot % upstream facts differ from its goods receipt line', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NEW.origin = 'REGRADE' THEN
                    SELECT product_id, collection_date, supplier_reference INTO parent FROM inventory_lots WHERE id = NEW.parent_lot_id;
                    IF parent.product_id = NEW.product_id THEN
                        RAISE EXCEPTION 'LOT_LINEAGE: a derived lot must change the product (lot %)', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.collection_date IS DISTINCT FROM parent.collection_date OR NEW.supplier_reference IS DISTINCT FROM parent.supplier_reference THEN
                        RAISE EXCEPTION 'LOT_UPSTREAM_MISMATCH: derived lot % must inherit its parent upstream facts', NEW.number USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$;
        SQL);
    }

    public function down(): void
    {
        // Development only. Restores the A5-B guard verbatim, then drops the columns.
        DB::unprepared(<<<'SQL'
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
            ALTER TABLE inventory_lots DROP CONSTRAINT IF EXISTS lot_upstream_check;
            ALTER TABLE inventory_lots DROP COLUMN IF EXISTS collection_date, DROP COLUMN IF EXISTS supplier_reference;
            ALTER TABLE goods_receipt_lines DROP CONSTRAINT IF EXISTS grl_upstream_check;
            ALTER TABLE goods_receipt_lines DROP COLUMN IF EXISTS collection_date, DROP COLUMN IF EXISTS supplier_reference;
        SQL);
    }
};
