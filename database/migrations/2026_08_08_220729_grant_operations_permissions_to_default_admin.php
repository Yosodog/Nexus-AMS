<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaultAdminRoleId = DB::table('roles')
            ->where('name', 'default admin')
            ->value('id');

        if ($defaultAdminRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                ['role_id' => $defaultAdminRoleId, 'permission' => 'coordinate-operations'],
                ['role_id' => $defaultAdminRoleId, 'permission' => 'manage-operations'],
                ['role_id' => $defaultAdminRoleId, 'permission' => 'view-milcom-operations'],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $defaultAdminRoleId = DB::table('roles')
            ->where('name', 'default admin')
            ->value('id');

        if ($defaultAdminRoleId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $defaultAdminRoleId)
                ->whereIn('permission', [
                    'coordinate-operations',
                    'manage-operations',
                    'view-milcom-operations',
                ])
                ->delete();
        }
    }
};
