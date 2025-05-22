<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = config('permissions');

        DB::transaction(function () use ($permissions) {
            $adminRole = Role::firstOrCreate(
                ['name' => 'admin'],
                ['protected' => true]
            );

            // Ensure the role remains protected even if modified manually
            $adminRole->update(['protected' => true]);

            // Sync all available permissions
            DB::table('role_permissions')
                ->where('role_id', $adminRole->id)
                ->delete();

            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $adminRole->id,
                    'permission' => $permission,
                ]);
            }
        });
    }
}
