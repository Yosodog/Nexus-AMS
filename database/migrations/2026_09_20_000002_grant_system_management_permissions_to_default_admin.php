<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['manage-system'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'default admin')->value('id');

        if ($roleId !== null) {
            DB::table('role_permissions')->insertOrIgnore(
                collect(self::PERMISSIONS)
                    ->map(fn (string $permission): array => [
                        'role_id' => $roleId,
                        'permission' => $permission,
                    ])
                    ->all(),
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'default admin')->value('id');

        if ($roleId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission', self::PERMISSIONS)
                ->delete();
        }
    }
};
