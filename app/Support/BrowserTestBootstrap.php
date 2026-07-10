<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Alliance;
use App\Models\AllianceFinanceEntry;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\DiscordAccount;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\Nation;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class BrowserTestBootstrap
{
    /**
     * Reset the isolated browser database and seed stable personas.
     *
     * @return array{admin: User, limited: User, member: User}
     */
    public function resetAndSeed(): array
    {
        $this->guardAgainstNonTestDatabases();

        $database = (string) config('database.connections.sqlite.database');
        if (! File::exists($database)) {
            File::ensureDirectoryExists(dirname($database));

            if (File::put($database, '') === false) {
                throw new RuntimeException("Browser test bootstrap could not create database [{$database}].");
            }
        }

        $this->recreateSchema();

        return DB::transaction(function (): array {
            Setting::query()->insert([
                ['key' => 'require_discord_verification', 'value' => '1'],
                ['key' => 'require_mfa_all_users', 'value' => '0'],
                ['key' => 'require_mfa_admins', 'value' => '0'],
                ['key' => 'grant_approvals_enabled', 'value' => '1'],
                ['key' => 'loan_applications_enabled', 'value' => '1'],
                ['key' => 'loan_payments_enabled', 'value' => '1'],
            ]);

            Alliance::query()->create([
                'id' => 9001,
                'name' => 'Browser Test Alliance',
                'acronym' => 'BTA',
                'score' => 42000,
                'color' => 'green',
                'average_score' => 2100,
                'accept_members' => true,
                'rank' => 42,
            ]);

            $memberNation = $this->createNation(
                id: 200001,
                nationName: 'Browser Member Nation',
                leaderName: 'Browser Member',
            );
            $adminNation = $this->createNation(
                id: 200002,
                nationName: 'Browser Admin Nation',
                leaderName: 'Browser Admin',
            );
            $limitedNation = $this->createNation(
                id: 200003,
                nationName: 'Browser Limited Nation',
                leaderName: 'Browser Limited',
            );

            $member = User::factory()
                ->verified()
                ->create([
                    'name' => 'Browser Member',
                    'email' => 'browser.member@example.test',
                    'nation_id' => $memberNation->id,
                    'last_active_at' => now(),
                ]);

            $admin = User::factory()
                ->verified()
                ->admin()
                ->create([
                    'name' => 'Browser Admin',
                    'email' => 'browser.admin@example.test',
                    'nation_id' => $adminNation->id,
                    'last_active_at' => now(),
                ]);

            $limited = User::factory()
                ->verified()
                ->admin()
                ->create([
                    'name' => 'Browser Limited',
                    'email' => 'browser.limited@example.test',
                    'nation_id' => $limitedNation->id,
                    'last_active_at' => now(),
                ]);

            $this->createDiscordAccount($member, '111111111111111111', 'browser-member');
            $this->createDiscordAccount($admin, '222222222222222222', 'browser-admin');
            $this->createDiscordAccount($limited, '333333333333333333', 'browser-limited');

            $memberAccount = $this->createAccount($memberNation, 'Operations reserve', 1250000);
            $this->createAccount($adminNation, 'Staff test account', 500000);

            $this->createOperationalFixtures($memberNation, $memberAccount);

            Page::query()->create([
                'slug' => 'browser-operations-guide',
                'status' => Page::STATUS_DRAFT,
                'draft' => '<h2>Browser operations guide</h2><p>Stable content for editor lifecycle checks.</p>',
            ]);

            $adminRole = Role::query()->create([
                'name' => 'Browser Full Admin',
                'protected' => false,
            ]);
            DB::table('role_permissions')->insert(
                collect(config('permissions', []))
                    ->map(fn (string $permission): array => [
                        'role_id' => $adminRole->id,
                        'permission' => $permission,
                    ])
                    ->all()
            );
            $admin->roles()->attach($adminRole);

            $limitedRole = Role::query()->create([
                'name' => 'Browser Limited Admin',
                'protected' => false,
            ]);
            DB::table('role_permissions')->insert([
                'role_id' => $limitedRole->id,
                'permission' => 'view-users',
            ]);
            $limited->roles()->attach($limitedRole);

            return [
                'admin' => $admin->fresh(),
                'limited' => $limited->fresh(),
                'member' => $member->fresh(),
            ];
        });
    }

    private function recreateSchema(): void
    {
        $wipeStatus = Artisan::call('db:wipe', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($wipeStatus !== 0) {
            throw new RuntimeException('Unable to wipe the browser test database: '.Artisan::output());
        }

        $migrationStatus = Artisan::call('migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($migrationStatus !== 0) {
            throw new RuntimeException('Unable to migrate the browser test database: '.Artisan::output());
        }
    }

    private function guardAgainstNonTestDatabases(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Browser test bootstrap may only run in the testing environment.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $normalizedDatabase = strtolower($database);

        if ($connection !== 'sqlite') {
            throw new RuntimeException('Browser test bootstrap may only run against an isolated SQLite database.');
        }

        if (
            $database === ''
            || $database === ':memory:'
            || $database === database_path('database.sqlite')
            || (! str_contains($normalizedDatabase, 'test') && ! str_contains($normalizedDatabase, 'browser'))
        ) {
            throw new RuntimeException('Browser test bootstrap refused to run against a non-isolated database.');
        }
    }

    private function createNation(int $id, string $nationName, string $leaderName): Nation
    {
        return Nation::factory()->create([
            'id' => $id,
            'alliance_id' => 9001,
            'nation_name' => $nationName,
            'leader_name' => $leaderName,
            'discord' => str($leaderName)->slug(),
            'flag' => null,
            'num_cities' => 12,
            'score' => 2450.75,
        ]);
    }

    private function createDiscordAccount(User $user, string $discordId, string $username): void
    {
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => $discordId,
            'discord_username' => $username,
        ]);
    }

    private function createAccount(Nation $nation, string $name, float $money): Account
    {
        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = $name;
        $account->money = $money;
        $account->food = 250000;
        $account->steel = 4200;
        $account->aluminum = 3600;
        $account->save();

        return $account;
    }

    private function createOperationalFixtures(Nation $memberNation, Account $memberAccount): void
    {
        $grant = new Grants;
        $grant->name = 'Infrastructure reserve';
        $grant->slug = 'infrastructure-reserve';
        $grant->description = 'A stable browser fixture for reviewing an exact mixed-resource payout.';
        $grant->money = 2500000;
        $grant->steel = 1250;
        $grant->aluminum = 900;
        $grant->is_enabled = true;
        $grant->is_one_time = false;
        $grant->save();

        GrantApplication::query()->create([
            'grant_id' => $grant->id,
            'nation_id' => $memberNation->id,
            'account_id' => $memberAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
            'money' => $grant->money,
            'steel' => $grant->steel,
            'aluminum' => $grant->aluminum,
        ]);

        CityGrant::query()->create([
            'description' => 'Baseline expansion support for browser review.',
            'enabled' => true,
            'grant_amount' => 4750000,
            'city_number' => 13,
            'requirements' => [],
        ]);

        CityGrantRequest::query()->create([
            'city_number' => 13,
            'grant_amount' => 4750000,
            'nation_id' => $memberNation->id,
            'account_id' => $memberAccount->id,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        Loan::query()->create([
            'nation_id' => $memberNation->id,
            'account_id' => $memberAccount->id,
            'amount' => 7500000,
            'remaining_balance' => 7500000,
            'interest_rate' => 3.5,
            'term_weeks' => 12,
            'status' => 'pending',
            'pending_key' => 1,
        ]);

        AllianceFinanceEntry::query()->create([
            'date' => now()->toDateString(),
            'direction' => AllianceFinanceEntry::DIRECTION_INCOME,
            'category' => 'tax',
            'description' => 'Member tax settlement',
            'nation_id' => $memberNation->id,
            'account_id' => $memberAccount->id,
            'money' => 2400000,
            'food' => 12000,
        ]);

        AllianceFinanceEntry::query()->create([
            'date' => now()->toDateString(),
            'direction' => AllianceFinanceEntry::DIRECTION_EXPENSE,
            'category' => 'grant',
            'description' => 'Infrastructure grant reserve',
            'nation_id' => $memberNation->id,
            'account_id' => $memberAccount->id,
            'money' => 750000,
            'steel' => 500,
        ]);
    }
}
