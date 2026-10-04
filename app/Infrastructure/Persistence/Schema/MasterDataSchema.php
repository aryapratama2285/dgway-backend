<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Reusable column groups and constraints for master-data tables, so the
 * address block and the code / version conventions are defined once instead
 * of being copy-pasted per table.
 */
final class MasterDataSchema
{
    public const CODE_REGEX = '^[A-Z0-9][A-Z0-9._-]{0,39}$';

    /** Embedded Address value object (all nullable; street required if any part is set). */
    public static function addressColumns(Blueprint $table, string $prefix = 'address_'): void
    {
        $table->string($prefix.'street', 255)->nullable();
        $table->string($prefix.'village', 150)->nullable();
        $table->string($prefix.'district', 150)->nullable();
        $table->string($prefix.'city', 150)->nullable();
        $table->string($prefix.'province', 150)->nullable();
        $table->string($prefix.'postal_code', 10)->nullable();
        $table->string($prefix.'landmark', 255)->nullable();
        $table->decimal($prefix.'latitude', 10, 7)->nullable();
        $table->decimal($prefix.'longitude', 10, 7)->nullable();
    }

    public static function addressConstraints(string $table, string $prefix = 'address_'): void
    {
        $p = $prefix;
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$p}street_required_check CHECK (
            {$p}street IS NOT NULL OR ({$p}village IS NULL AND {$p}district IS NULL AND {$p}city IS NULL
            AND {$p}province IS NULL AND {$p}postal_code IS NULL AND {$p}landmark IS NULL AND {$p}latitude IS NULL))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$p}postal_code_check CHECK ({$p}postal_code IS NULL OR {$p}postal_code ~ '^[0-9]{5}$')");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$p}coordinates_check CHECK (
            ({$p}latitude IS NULL) = ({$p}longitude IS NULL)
            AND ({$p}latitude IS NULL OR {$p}latitude BETWEEN -90 AND 90)
            AND ({$p}longitude IS NULL OR {$p}longitude BETWEEN -180 AND 180))");
    }

    /** Business code: UNIQUE, upper-case, restricted characters. */
    public static function codeConstraint(string $table, string $column = 'code'): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_format_check CHECK ({$column} ~ '".self::CODE_REGEX."')");
    }

    /** Optimistic concurrency version column. */
    public static function versionColumn(Blueprint $table): void
    {
        $table->integer('version')->default(1);
    }

    public static function versionConstraint(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_version_check CHECK (version >= 1)");
    }
}
