<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F4B — payment method OTHER.
 *
 * 1. payments.method and customer_returns.refund_method accept OTHER next to
 *    CASH, BANK_TRANSFER and QRIS (the two CHECKs are widened; their other
 *    conditions are recreated unchanged).
 * 2. An OTHER payment is never anonymous: its existing `reference` or `notes`
 *    column must contain text (pay_other_described_check). A refund with
 *    OTHER needs nothing extra: customer_returns.reason is already mandatory.
 *
 * No column is added; existing rows are untouched; shipped migrations are not
 * edited. down() refuses once an OTHER payment or refund exists, so a rollback
 * can never invalidate history.
 */
return new class extends Migration
{
    private const OLD_METHODS = "'CASH','BANK_TRANSFER','QRIS'";

    private const NEW_METHODS = "'CASH','BANK_TRANSFER','QRIS','OTHER'";

    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_method_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_method_check CHECK (method IN ('.self::NEW_METHODS.'))');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT pay_other_described_check CHECK (
            method <> 'OTHER' OR length(trim(COALESCE(reference, ''))) > 0 OR length(trim(COALESCE(notes, ''))) > 0)");

        DB::statement('ALTER TABLE customer_returns DROP CONSTRAINT cr_refund_method_check');
        DB::statement(self::refundCheck(self::NEW_METHODS));
    }

    public function down(): void
    {
        $other = DB::selectOne("SELECT (SELECT count(*) FROM payments WHERE method = 'OTHER') + (SELECT count(*) FROM customer_returns WHERE refund_method = 'OTHER') AS n");
        if ((int) $other->n > 0) {
            throw new RuntimeException('Refusing to roll back F4B: payments or refunds with method OTHER exist.');
        }

        DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_other_described_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT pay_method_check');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT pay_method_check CHECK (method IN ('.self::OLD_METHODS.'))');

        DB::statement('ALTER TABLE customer_returns DROP CONSTRAINT cr_refund_method_check');
        DB::statement(self::refundCheck(self::OLD_METHODS));
    }

    /** The A4 refund-method CHECK, recreated with [$methods]. */
    private static function refundCheck(string $methods): string
    {
        return 'ALTER TABLE customer_returns ADD CONSTRAINT cr_refund_method_check CHECK ((refund_amount > 0) = (refund_method IS NOT NULL)
            AND (refund_method IS NULL OR refund_method IN ('.$methods.')))';
    }
};
