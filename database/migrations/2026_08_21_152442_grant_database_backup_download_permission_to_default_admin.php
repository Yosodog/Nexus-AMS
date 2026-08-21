<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultAdminRoleId = DB::table('roles')
            ->where('name', 'default admin')
            ->value('id');

        if ($defaultAdminRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $defaultAdminRoleId,
                'permission' => 'download-database-backups',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')
            ->where('permission', 'download-database-backups')
            ->delete();
    }
};
