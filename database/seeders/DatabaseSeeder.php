<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Phase A1 has no business seed data.
 *
 * Reference data that the system needs to function (units of measure) is
 * inserted by migrations. Operator accounts are NEVER seeded with default
 * passwords; create them with:
 *
 *   php artisan dgway:operator:create owner@example.com --name="Nama Owner"
 *
 * Demo master data (customers, suppliers, products) arrives with Phase A2.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
