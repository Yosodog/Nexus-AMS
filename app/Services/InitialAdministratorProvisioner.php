<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ApplyPageSeeder;
use Database\Seeders\DirectDepositDefaultBracketSeeder;
use Database\Seeders\MMRSettingSeeder;
use Database\Seeders\MMRTierZeroSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class InitialAdministratorProvisioner
{
    /** @param array{email: string, password: string, nation_id: int} $attributes */
    public function provision(array $attributes): bool
    {
        $created = DB::transaction(function () use ($attributes): bool {
            $adminRole = Role::query()->lockForUpdate()->firstOrCreate(
                ['name' => 'default admin'],
                ['protected' => true],
            );
            $adminRole->forceFill(['protected' => true])->save();

            foreach ((array) config('permissions', []) as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $adminRole->getKey(),
                    'permission' => $permission,
                ]);
            }

            $existingAdministrator = User::query()
                ->where('is_admin', true)
                ->lockForUpdate()
                ->first();
            $matchingUser = User::query()
                ->where(function ($query) use ($attributes): void {
                    $query->where('email', $attributes['email'])
                        ->orWhere('nation_id', $attributes['nation_id']);
                })
                ->lockForUpdate()
                ->first();

            if ($existingAdministrator !== null) {
                if ($matchingUser === null || ! $existingAdministrator->is($matchingUser)) {
                    throw new RuntimeException('A different Nexus administrator already exists.');
                }

                if ($matchingUser->email !== $attributes['email'] || (int) $matchingUser->nation_id !== $attributes['nation_id']) {
                    throw new RuntimeException('The existing administrator identity does not match.');
                }

                $matchingUser->roles()->syncWithoutDetaching([$adminRole->getKey()]);

                return false;
            }

            if ($matchingUser !== null) {
                throw new RuntimeException('The requested administrator identity is already in use.');
            }

            $user = User::query()->create([
                'name' => 'Nexus Administrator',
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                'nation_id' => $attributes['nation_id'],
                'verification_code' => null,
                'verified_at' => now(),
            ]);
            $user->forceFill([
                'is_admin' => true,
                'disabled' => false,
            ])->save();
            $user->roles()->syncWithoutDetaching([$adminRole->getKey()]);

            return true;
        }, 3);

        $this->provisionInitialDefaults();

        return $created;
    }

    private function provisionInitialDefaults(): void
    {
        /** @var list<class-string<Seeder>> $seeders */
        $seeders = [
            DirectDepositDefaultBracketSeeder::class,
            MMRTierZeroSeeder::class,
            MMRSettingSeeder::class,
            ApplyPageSeeder::class,
        ];

        foreach ($seeders as $seeder) {
            app($seeder)->run();
        }
    }
}
