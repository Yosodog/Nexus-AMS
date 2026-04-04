<?php

namespace Tests\Concerns;

use App\Models\DiscordAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

trait BuildsTestUsers
{
    protected function createVerifiedUser(array $attributes = []): User
    {
        return User::factory()
            ->verified()
            ->create($attributes);
    }

    protected function createVerifiedAdmin(array $attributes = []): User
    {
        return User::factory()
            ->verified()
            ->admin()
            ->create($attributes);
    }

    protected function attachDiscordAccount(User $user, array $attributes = []): DiscordAccount
    {
        return DiscordAccount::factory()->create([
            'user_id' => $user->id,
            ...$attributes,
        ]);
    }

    protected function enableTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    protected function grantPermissions(User $user, array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Test Role '.uniqid(),
            'protected' => false,
        ]);

        Collection::make($permissions)
            ->unique()
            ->each(fn (string $permission) => DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission' => $permission,
            ]));

        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function actingAsSanctum(User $user, array $abilities = ['*']): User
    {
        Sanctum::actingAs($user, $abilities);

        return $user;
    }
}
