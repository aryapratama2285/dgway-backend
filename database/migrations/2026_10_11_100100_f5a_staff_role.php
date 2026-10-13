<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F5A — STAFF operator role (decision D1).
 *
 * users.role accepted only OWNER; it now accepts OWNER and STAFF. What a role
 * may do stays configuration (config/dgway.php authorization.role_permissions):
 * STAFF holds none of the sensitive permissions. No column is added and no
 * existing row changes. down() refuses while any STAFF account exists, so a
 * rollback never invalidates an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('OWNER', 'STAFF'))");
    }

    public function down(): void
    {
        $staff = DB::selectOne("SELECT count(*) AS n FROM users WHERE role = 'STAFF'");
        if ((int) $staff->n > 0) {
            throw new RuntimeException('Refusing to roll back F5A: STAFF operator accounts exist.');
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('OWNER'))");
    }
};
