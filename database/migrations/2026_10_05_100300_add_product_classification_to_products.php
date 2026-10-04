<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies the primary economic/operational role of an inventory-managed SKU.
 *
 * Existing products are merchandise by default, preserving the pre-F3
 * behaviour where every Product was treated as a sellable/purchasable item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('classification', 20)
                ->default('SELLABLE')
                ->after('category_id');
        });

        DB::statement(
            "ALTER TABLE products
             ADD CONSTRAINT products_classification_check
             CHECK (classification IN ('SELLABLE', 'PACKAGING', 'CONSUMABLE'))"
        );
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('classification');
        });
    }
};
