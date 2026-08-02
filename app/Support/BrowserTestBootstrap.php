<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Alliance;
use App\Models\AllianceFinanceEntry;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
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
            $this->createAuditFixtures($memberNation, $admin);

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
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
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

    private function createAuditFixtures(Nation $memberNation, User $admin): void
    {
        $definition = [
            'schema_version' => 1,
            'criteria' => [
                'group' => 'all',
                'rules' => [[
                    'id' => '11111111-1111-4111-8111-111111111111',
                    'field' => 'nation.aircraft_per_city',
                    'operator' => 'lt',
                    'value' => 50,
                ]],
            ],
            'exceptions' => [
                'group' => 'any',
                'rules' => [],
            ],
        ];

        $rule = AuditRule::query()->create([
            'name' => 'Aircraft readiness below target',
            'description' => 'Your nation has fewer aircraft per city than the alliance readiness target.',
            'remediation_guidance' => 'Purchase aircraft until you have at least 50 aircraft for each city.',
            'admin_notes' => 'Stable browser fixture for the findings-first report.',
            'target_type' => 'nation',
            'priority' => 'high',
            'definition' => $definition,
            'revision' => 1,
            'enabled' => true,
            'last_evaluation_status' => 'success',
            'last_evaluated_at' => now()->subMinutes(15),
            'last_match_count' => 1,
            'last_evaluation_duration_ms' => 12,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $finding = AuditResult::query()->create([
            'audit_rule_id' => $rule->id,
            'rule_revision' => 1,
            'target_type' => 'nation',
            'target_key' => (string) $memberNation->id,
            'nation_id' => $memberNation->id,
            'city_id' => null,
            'details' => [
                'rule_revision' => 1,
                'summary' => 'Aircraft per city is less than 50.',
                'evidence' => [
                    [
                        'scope' => 'criteria',
                        'field_label' => 'Aircraft per city',
                        'condition' => 'Aircraft per city is less than 50 aircraft per city.',
                        'observed_display' => '35 aircraft per city',
                        'expected_display' => '50 aircraft per city',
                        'operator_label' => 'is less than',
                        'matched' => true,
                        'member_safe' => true,
                    ],
                    [
                        'scope' => 'criteria',
                        'field_label' => 'City count',
                        'condition' => 'This readiness target is evaluated across 12 cities.',
                        'observed_display' => '12 cities',
                        'expected_display' => 'Current nation city count',
                        'operator_label' => 'is available',
                        'matched' => true,
                        'member_safe' => true,
                    ],
                ],
                'evaluated_at' => now()->subMinutes(15)->toIso8601String(),
            ],
            'first_detected_at' => now()->subDays(3),
            'last_evaluated_at' => now()->subMinutes(15),
            'due_at' => now()->subDay(),
        ]);

        AuditResultEvent::query()->create([
            'audit_result_id' => $finding->id,
            'audit_rule_id' => $rule->id,
            'target_type' => 'nation',
            'target_key' => (string) $memberNation->id,
            'nation_id' => $memberNation->id,
            'city_id' => null,
            'actor_user_id' => null,
            'event_type' => 'opened',
            'metadata' => [
                'rule_snapshot' => [
                    'name' => $rule->name,
                    'priority' => 'high',
                    'revision' => 1,
                    'summary' => 'Aircraft per city is less than 50.',
                ],
            ],
            'occurred_at' => now()->subDays(3),
        ]);

        AuditRule::query()->create([
            'name' => 'Imported rule needing rebuild',
            'description' => 'This imported audit check needs a guided replacement.',
            'remediation_guidance' => null,
            'admin_notes' => null,
            'target_type' => 'nation',
            'priority' => 'medium',
            'definition' => null,
            'revision' => 1,
            'enabled' => false,
            'last_evaluation_status' => 'migration_failed',
            'last_match_count' => 0,
            'last_evaluation_error' => 'This imported rule could not be converted safely. Rebuild it with the guided rule editor.',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }
}
