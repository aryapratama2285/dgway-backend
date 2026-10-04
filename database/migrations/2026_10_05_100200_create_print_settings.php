<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A5-C — business identity printed on receipts / documents / labels.
 *
 * One row at most (unique index on a constant). While the row does not exist
 * the application serves the configured defaults (config/dgway.php `print`:
 * DG WAY / Supply & Distribution / 80 mm). Printer hardware (pairing, model,
 * Bluetooth address) is NOT stored here: that belongs to the device/Flutter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('business_name', 60);
            $t->string('business_subtitle', 60)->nullable();
            $t->string('address', 200)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('footer_text', 200)->nullable();
            $t->smallInteger('receipt_width_mm');
            $t->integer('version');
            $t->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz(6);
        });
        DB::statement('CREATE UNIQUE INDEX print_settings_singleton ON print_settings ((true))');
        DB::statement('ALTER TABLE print_settings ADD CONSTRAINT print_settings_width_check CHECK (receipt_width_mm IN (58, 80))');
        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_name_check CHECK (btrim(business_name) <> '')");
        DB::statement('ALTER TABLE print_settings ADD CONSTRAINT print_settings_version_check CHECK (version >= 1)');
        DB::unprepared("CREATE TRIGGER print_settings_identity_guard BEFORE UPDATE OR DELETE ON print_settings FOR EACH ROW EXECUTE FUNCTION dgway_guard_identity('id')");
    }

    public function down(): void
    {
        Schema::dropIfExists('print_settings');
    }
};
