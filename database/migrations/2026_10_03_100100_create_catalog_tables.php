<?php

use App\Infrastructure\Persistence\Schema\MasterDataSchema as S;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog master data: product categories (tree) and products (one row per SKU).
 *
 * Category-specific attributes and product-specific UOM conversions are
 * future extension tables keyed by product_id; they are intentionally not
 * columns of `products`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->uuid('parent_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            S::versionColumn($table);
            $table->timestampsTz();
        });
        // Self-reference added after the table (and its primary key) exist.
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('product_categories')->restrictOnDelete();
        });
        S::codeConstraint('product_categories');
        S::versionConstraint('product_categories');
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_categories_not_own_parent_check CHECK (parent_id IS NULL OR parent_id <> id)');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 40)->unique();
            $table->string('name', 150);
            $table->foreignUuid('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('base_uom_code', 16);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            S::versionColumn($table);
            $table->timestampsTz();

            $table->foreign('base_uom_code')->references('code')->on('uoms')->restrictOnDelete()->cascadeOnUpdate();
            $table->index(['is_active', 'name']);
        });
        S::codeConstraint('products', 'sku');
        S::versionConstraint('products');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_description_length_check CHECK (description IS NULL OR char_length(description) <= 1000)');
        DB::statement('CREATE INDEX products_name_lower_idx ON products (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
