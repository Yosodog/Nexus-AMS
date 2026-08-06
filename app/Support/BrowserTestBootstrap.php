<?php

namespace App\Support;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\IncidentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Enums\DiscordQueueStatus;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\AllianceFinanceEntry;
use App\Models\AuditResult;
use App\Models\AuditResultEvent;
use App\Models\AuditRule;
use App\Models\CityGrant;
use App\Models\CityGrantRequest;
use App\Models\DiscordAccount;
use App\Models\DiscordQueue;
use App\Models\GrantApplication;
use App\Models\Grants;
use App\Models\Loan;
use App\Models\MilcomAssignment;
use App\Models\MilcomDispatch;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\Page;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\War;
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
                ['key' => 'applications_enabled', 'value' => '1'],
                ['key' => 'loan_applications_enabled', 'value' => '1'],
                ['key' => 'loan_payments_enabled', 'value' => '1'],
                ['key' => 'discord_war_room_forum_id', 'value' => '444444444444444444'],
                ['key' => 'discord_war_room_defense_role_id', 'value' => '555555555555555555'],
                ['key' => 'milcom_counter_monitoring_enabled', 'value' => '1'],
            ]);

            Alliance::query()->create([
                'id' => 9001,
                'name' => 'Browser Test Alliance',
                'acronym' => 'BTA',
                'score' => 42000,
                'color' => 'green',
                'average_score' => 2100,
                'accept_members' => true,
                'flag' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
                'discord_link' => 'https://discord.gg/browser-test-alliance',
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
            $this->createMilcomFixtures($admin, [$memberNation, $adminNation, $limitedNation]);

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

    /** @param list<Nation> $friendlyNations */
    private function createMilcomFixtures(User $admin, array $friendlyNations): void
    {
        $enemyAlliance = Alliance::query()->create([
            'id' => 9002,
            'name' => 'Browser Test Opposition',
            'acronym' => 'BTO',
            'score' => 18000,
            'color' => 'red',
            'average_score' => 2400,
            'accept_members' => true,
            'flag' => 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
            'rank' => 84,
        ]);
        $counterTarget = Nation::factory()->create([
            'id' => 210001,
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => 'Browser Counter Aggressor',
            'leader_name' => 'Counter Leader',
            'num_cities' => 12,
            'score' => 2450,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]);
        $planTargets = collect([
            ['id' => 210002, 'nation_name' => 'Browser Critical Target', 'leader_name' => 'Critical Leader'],
            ['id' => 210003, 'nation_name' => 'Browser Standard Target', 'leader_name' => 'Standard Leader'],
        ])->map(fn (array $attributes): Nation => Nation::factory()->create([
            ...$attributes,
            'alliance_id' => $enemyAlliance->id,
            'num_cities' => 12,
            'score' => 2475,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]));

        $plan = MilcomOperation::query()->create([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Review,
            'current_stage' => 'staffing',
            'name' => 'Browser Coalition Dawn',
            'doctrine_version' => 'fixed-v1',
            'default_war_type' => 'ORDINARY',
            'default_war_reason' => 'Browser mass-war exercise',
            'discord_forum_id' => '444444444444444444',
            'generation_version' => 1,
            'dispatch_version' => 0,
            'created_by' => $admin->id,
            'metadata' => ['wave' => 1],
        ]);
        $plan->alliances()->createMany([
            ['alliance_id' => 9001, 'role' => 'friendly', 'included' => true],
            ['alliance_id' => $enemyAlliance->id, 'role' => 'enemy', 'included' => true],
        ]);
        $criticalObjective = $this->createBrowserObjective(
            $plan,
            $planTargets[0],
            PriorityTier::Critical,
            ObjectiveStatus::Review,
        );
        $standardObjective = $this->createBrowserObjective(
            $plan,
            $planTargets[1],
            PriorityTier::Standard,
            ObjectiveStatus::Review,
        );
        $planRun = MilcomRecommendationRun::query()->create([
            'operation_id' => $plan->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', 'browser-plan-run'),
            'trigger' => 'browser_fixture',
            'progress_percent' => 100,
            'generation_version' => 1,
            'objectives_total' => 2,
            'objectives_processed' => 2,
            'elapsed_ms' => 180,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        foreach ($friendlyNations as $friendlyNation) {
            $this->createMilcomSnapshot($planRun, $friendlyNation, 'friendly', true);
        }

        foreach ($planTargets as $planTarget) {
            $this->createMilcomSnapshot($planRun, $planTarget, 'target', false);
        }

        $planTeams = [
            $criticalObjective->id => [$friendlyNations[0], $friendlyNations[1]],
            $standardObjective->id => [$friendlyNations[2]],
        ];

        foreach ([$criticalObjective, $standardObjective] as $planObjective) {
            $team = $planTeams[$planObjective->id];

            foreach ($team as $index => $friendlyNation) {
                MilcomAssignment::query()->create([
                    'objective_id' => $planObjective->id,
                    'friendly_nation_id' => $friendlyNation->id,
                    'score' => 92 - $index,
                    'confidence' => 100,
                    'rank' => $index + 1,
                    'status' => AssignmentStatus::Proposed,
                    'is_locked' => false,
                    'recommendation_run_id' => $planRun->id,
                    'factor_explanations' => ['score' => 92 - $index, 'confidence' => 100],
                ]);
            }

            MilcomObjectiveRecommendation::query()->create([
                'recommendation_run_id' => $planRun->id,
                'objective_id' => $planObjective->id,
                'team_score' => 91,
                'confidence' => 100,
                'proposed_team' => [
                    'nation_ids' => collect($team)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'score' => 91,
                    'partial' => false,
                ],
                'alternatives' => [],
                'blocker_summary' => [],
                'factor_explanations' => ['members' => [], 'warnings' => []],
            ]);
            $planObjective->forceFill(['latest_recommendation_run_id' => $planRun->id])->save();
        }

        $war = War::query()->create([
            'id' => 98_001,
            'date' => now(),
            'reason' => 'Browser incoming defensive war',
            'war_type' => 'ORDINARY',
            'turns_left' => 60,
            'att_id' => $counterTarget->id,
            'att_alliance_id' => $counterTarget->alliance_id,
            'att_alliance_position' => $counterTarget->alliance_position,
            'def_id' => $friendlyNations[0]->id,
            'def_alliance_id' => $friendlyNations[0]->alliance_id,
            'def_alliance_position' => $friendlyNations[0]->alliance_position,
        ]);
        $incident = MilcomIncident::query()->create([
            'war_id' => $war->id,
            'attacked_nation_id' => $friendlyNations[0]->id,
            'aggressor_nation_id' => $counterTarget->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subMinute(),
        ]);
        $counter = MilcomOperation::query()->create([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Review,
            'current_stage' => 'dispatch',
            'name' => 'Counter: Browser Counter Aggressor',
            'doctrine_version' => 'fixed-v1',
            'default_war_type' => 'ORDINARY',
            'default_war_reason' => 'Defend Browser Member Nation',
            'discord_forum_id' => '444444444444444444',
            'generation_version' => 1,
            'dispatch_version' => 0,
            'created_by' => $admin->id,
            'metadata' => ['war_id' => $war->id],
        ]);
        $counter->alliances()->create([
            'alliance_id' => 9001,
            'role' => 'friendly',
            'included' => true,
        ]);
        $objective = MilcomObjective::query()->create([
            'operation_id' => $counter->id,
            'target_nation_id' => $counterTarget->id,
            'priority_tier' => PriorityTier::Critical,
            'priority_score' => 100,
            'desired_team_depth' => 3,
            'minimum_team_depth' => 3,
            'war_type' => 'ORDINARY',
            'war_reason' => 'Defend Browser Member Nation',
            'deadline_at' => now()->addHours(6),
            'status' => ObjectiveStatus::Review,
            'source_incident_id' => $incident->id,
            'open_key' => 1,
            'generation_version' => 1,
            'dispatch_version' => 0,
        ]);
        $incident->forceFill(['objective_id' => $objective->id])->save();
        $run = MilcomRecommendationRun::query()->create([
            'operation_id' => $counter->id,
            'objective_id' => $objective->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', 'browser-counter-run'),
            'trigger' => 'browser_fixture',
            'progress_percent' => 100,
            'generation_version' => 1,
            'objectives_total' => 1,
            'objectives_processed' => 1,
            'elapsed_ms' => 120,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $this->createMilcomSnapshot($run, $counterTarget, 'target', false);

        $counterReserve = Nation::factory()->create([
            'id' => 210005,
            'alliance_id' => 9001,
            'nation_name' => 'Browser Counter Reserve',
            'leader_name' => 'Reserve Leader',
            'num_cities' => 12,
            'score' => 2425,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]);
        $counterTeam = [$friendlyNations[1], $friendlyNations[2], $counterReserve];

        foreach ($counterTeam as $index => $friendlyNation) {
            $this->createMilcomSnapshot($run, $friendlyNation, 'friendly', true);
            MilcomAssignment::query()->create([
                'objective_id' => $objective->id,
                'friendly_nation_id' => $friendlyNation->id,
                'score' => 94 - $index,
                'confidence' => 100,
                'rank' => $index + 1,
                'status' => AssignmentStatus::Proposed,
                'is_locked' => false,
                'recommendation_run_id' => $run->id,
                'factor_explanations' => [
                    'score' => 94 - $index,
                    'confidence' => 100,
                    'factors' => ['air_matchup' => 95, 'ground_matchup' => 92, 'naval_matchup' => 90],
                ],
            ]);
        }

        MilcomObjectiveRecommendation::query()->create([
            'recommendation_run_id' => $run->id,
            'objective_id' => $objective->id,
            'team_score' => 93,
            'confidence' => 100,
            'proposed_team' => [
                'nation_ids' => collect($counterTeam)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'score' => 93,
                'partial' => false,
            ],
            'alternatives' => [],
            'blocker_summary' => [],
            'factor_explanations' => ['members' => [], 'warnings' => []],
        ]);
        $objective->forceFill(['latest_recommendation_run_id' => $run->id])->save();

        $failedTarget = Nation::factory()->create([
            'id' => 210004,
            'alliance_id' => $enemyAlliance->id,
            'nation_name' => 'Browser Failed Delivery Target',
            'leader_name' => 'Retry Leader',
            'num_cities' => 12,
            'score' => 2400,
            'alliance_position' => 'MEMBER',
            'alliance_position_id' => 2,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]);
        $failedWar = War::query()->create([
            'id' => 98_002,
            'date' => now(),
            'reason' => 'Browser failed delivery war',
            'war_type' => 'ORDINARY',
            'turns_left' => 60,
            'att_id' => $failedTarget->id,
            'att_alliance_id' => $failedTarget->alliance_id,
            'att_alliance_position' => $failedTarget->alliance_position,
            'def_id' => $friendlyNations[1]->id,
            'def_alliance_id' => $friendlyNations[1]->alliance_id,
            'def_alliance_position' => $friendlyNations[1]->alliance_position,
        ]);
        $failedIncident = MilcomIncident::query()->create([
            'war_id' => $failedWar->id,
            'attacked_nation_id' => $friendlyNations[1]->id,
            'aggressor_nation_id' => $failedTarget->id,
            'status' => IncidentStatus::Countering,
            'detected_at' => now()->subMinutes(2),
        ]);
        $failedOperation = MilcomOperation::query()->create([
            'type' => OperationType::Counter,
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'name' => 'Counter: Browser Failed Delivery Target',
            'doctrine_version' => 'fixed-v1',
            'default_war_type' => 'ORDINARY',
            'default_war_reason' => 'Browser retry exercise',
            'discord_forum_id' => '444444444444444444',
            'generation_version' => 1,
            'dispatch_version' => 1,
            'created_by' => $admin->id,
        ]);
        $failedObjective = MilcomObjective::query()->create([
            'operation_id' => $failedOperation->id,
            'target_nation_id' => $failedTarget->id,
            'priority_tier' => PriorityTier::Critical,
            'priority_score' => 100,
            'desired_team_depth' => 3,
            'minimum_team_depth' => 3,
            'war_type' => 'ORDINARY',
            'war_reason' => 'Browser retry exercise',
            'status' => ObjectiveStatus::Dispatched,
            'source_incident_id' => $failedIncident->id,
            'open_key' => 1,
            'generation_version' => 1,
            'dispatch_version' => 1,
            'dispatched_at' => now()->subMinute(),
        ]);
        $failedIncident->forceFill(['objective_id' => $failedObjective->id])->save();
        $queueItem = DiscordQueue::query()->create([
            'action' => 'WAR_ROOM_CREATE',
            'dedupe_key' => "milcom-objective:{$failedObjective->id}:room:v1",
            'payload' => [],
            'status' => DiscordQueueStatus::Failed,
            'attempts' => 3,
            'available_at' => now(),
            'result' => ['discord_channel_id' => '666666666666666666'],
            'last_error' => ['code' => 'discord_send_failed', 'message' => 'Browser fixture delivery failed.'],
        ]);
        MilcomDispatch::query()->create([
            'operation_id' => $failedOperation->id,
            'objective_id' => $failedObjective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Failed,
            'queue_id' => $queueItem->id,
            'dedupe_key' => $queueItem->dedupe_key,
            'payload_snapshot' => [],
            'errors' => $queueItem->last_error,
            'queued_at' => now()->subMinutes(2),
            'failed_at' => now()->subMinute(),
        ]);
    }

    private function createBrowserObjective(
        MilcomOperation $operation,
        Nation $target,
        PriorityTier $tier,
        ObjectiveStatus $status,
    ): MilcomObjective {
        $depth = $tier->defaultDepth();

        return MilcomObjective::query()->create([
            'operation_id' => $operation->id,
            'target_nation_id' => $target->id,
            'priority_tier' => $tier,
            'priority_score' => $tier === PriorityTier::Critical ? 100 : 50,
            'desired_team_depth' => $depth['desired'],
            'minimum_team_depth' => $depth['minimum'],
            'war_type' => 'ORDINARY',
            'war_reason' => 'Browser mass-war exercise',
            'status' => $status,
            'generation_version' => 1,
            'dispatch_version' => 0,
        ]);
    }

    private function createMilcomSnapshot(
        MilcomRecommendationRun $run,
        Nation $nation,
        string $role,
        bool $discordLinked,
    ): void {
        $military = $role === 'friendly'
            ? ['soldiers' => 150000, 'tanks' => 10000, 'aircraft' => 1200, 'ships' => 120, 'missiles' => 0, 'nukes' => 0]
            : ['soldiers' => 80000, 'tanks' => 5000, 'aircraft' => 700, 'ships' => 60, 'missiles' => 0, 'nukes' => 0];
        $payload = [
            'nation_id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'alliance_position' => $nation->alliance_position,
            'score' => (float) $nation->score,
            'cities' => (int) $nation->num_cities,
            'vacation_turns' => 0,
            'beige_turns' => 0,
            'active_offensive_wars' => 0,
            'reserved_offensive_slots' => 0,
            'military' => $military,
            'last_active_at' => now()->toIso8601String(),
            'fetched_at' => now()->toIso8601String(),
            'discord_linked' => $discordLinked,
            'projects' => [],
        ];

        MilcomReadinessSnapshot::query()->create([
            'recommendation_run_id' => $run->id,
            'nation_id' => $nation->id,
            'role' => $role,
            'alliance_id' => $nation->alliance_id,
            'alliance_position' => $nation->alliance_position,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'vacation_turns' => 0,
            'beige_turns' => 0,
            'offensive_capacity' => 5,
            'active_offensive_wars' => 0,
            'reserved_offensive_slots' => 0,
            ...$military,
            'last_active_at' => now(),
            'fetched_at' => now(),
            'completeness_percent' => 100,
            'payload' => $payload,
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
