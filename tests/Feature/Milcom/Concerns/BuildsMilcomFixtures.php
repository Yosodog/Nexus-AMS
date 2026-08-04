<?php

namespace Tests\Feature\Milcom\Concerns;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\Alliance;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\MilcomReadinessSnapshot;
use App\Models\MilcomRecommendationRun;
use App\Models\Nation;
use App\Models\User;
use App\Models\War;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTestUsers;

trait BuildsMilcomFixtures
{
    use BuildsTestUsers;

    protected function createMilcomManager(array $attributes = []): User
    {
        $manager = $this->createVerifiedAdmin($attributes);
        $this->attachDiscordAccount($manager);

        return $this->grantPermissions($manager, ['manage-war-room']);
    }

    protected function authenticateMilcomManager(array $attributes = []): User
    {
        $manager = $this->createMilcomManager($attributes);
        $this->actingAsSanctum($manager);

        return $manager;
    }

    protected function createMilcomOperation(array $attributes = []): MilcomOperation
    {
        return MilcomOperation::query()->create(array_merge([
            'type' => OperationType::Plan,
            'status' => OperationStatus::Review,
            'current_stage' => 'staffing',
            'name' => 'Operation '.fake()->unique()->words(2, true),
            'doctrine_version' => 'fixed-v1',
            'default_war_type' => 'ORDINARY',
            'default_war_reason' => 'Alliance operations',
            'generation_version' => 1,
            'dispatch_version' => 0,
            'metadata' => ['wave' => 1],
        ], $attributes));
    }

    protected function createMilcomObjective(
        MilcomOperation $operation,
        Nation $target,
        array $attributes = [],
    ): MilcomObjective {
        return MilcomObjective::query()->create(array_merge([
            'operation_id' => $operation->id,
            'target_nation_id' => $target->id,
            'priority_tier' => PriorityTier::Standard,
            'priority_score' => 50,
            'desired_team_depth' => 1,
            'minimum_team_depth' => 1,
            'war_type' => 'ORDINARY',
            'war_reason' => 'Alliance operations',
            'status' => ObjectiveStatus::Review,
            'generation_version' => $operation->generation_version,
            'dispatch_version' => 0,
        ], $attributes));
    }

    protected function addFriendlyScope(MilcomOperation $operation, Alliance $alliance): void
    {
        $operation->alliances()->firstOrCreate([
            'alliance_id' => $alliance->id,
            'role' => 'friendly',
        ], [
            'included' => true,
        ]);
    }

    protected function createAssignment(
        MilcomObjective $objective,
        Nation $friendly,
        array $attributes = [],
    ): MilcomAssignment {
        return MilcomAssignment::query()->create(array_merge([
            'objective_id' => $objective->id,
            'friendly_nation_id' => $friendly->id,
            'score' => 88.5,
            'confidence' => 99,
            'rank' => 1,
            'status' => AssignmentStatus::Proposed,
            'is_locked' => false,
        ], $attributes));
    }

    /**
     * @param  list<Nation>  $friendlies
     * @param  array<int, array<string, mixed>>  $friendlySnapshotOverrides
     */
    protected function attachSuccessfulRecommendation(
        MilcomObjective $objective,
        array $friendlies,
        array $friendlySnapshotOverrides = [],
        array $targetSnapshotOverrides = [],
    ): MilcomRecommendationRun {
        $operation = $objective->operation;
        $run = MilcomRecommendationRun::query()->create([
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'status' => RecommendationRunStatus::Succeeded,
            'algorithm_version' => 'fixed-v1',
            'input_hash' => hash('sha256', "milcom-test-{$objective->id}"),
            'trigger' => 'test',
            'progress_percent' => 100,
            'generation_version' => $operation->generation_version,
            'objectives_total' => 1,
            'objectives_processed' => 1,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);

        $this->createReadinessSnapshot($run, $objective->target, 'target', $targetSnapshotOverrides);

        foreach ($friendlies as $friendly) {
            $this->createReadinessSnapshot(
                $run,
                $friendly,
                'friendly',
                $friendlySnapshotOverrides[(int) $friendly->id] ?? [],
            );
        }

        $objective->forceFill([
            'latest_recommendation_run_id' => $run->id,
            'status' => ObjectiveStatus::Review,
        ])->save();

        return $run;
    }

    protected function createReadinessSnapshot(
        MilcomRecommendationRun $run,
        Nation $nation,
        string $role,
        array $overrides = [],
    ): MilcomReadinessSnapshot {
        $fetchedAt = Carbon::parse($overrides['fetched_at'] ?? now());
        $lastActiveAt = array_key_exists('last_active_at', $overrides)
            ? ($overrides['last_active_at'] !== null ? Carbon::parse($overrides['last_active_at']) : null)
            : now();
        $military = array_merge([
            'soldiers' => $role === 'friendly' ? 150_000 : 80_000,
            'tanks' => $role === 'friendly' ? 10_000 : 5_000,
            'aircraft' => $role === 'friendly' ? 1_200 : 700,
            'ships' => $role === 'friendly' ? 120 : 60,
            'missiles' => 0,
            'nukes' => 0,
        ], $overrides['military'] ?? []);
        $projects = $overrides['projects'] ?? [];
        $discordLinked = (bool) ($overrides['discord_linked'] ?? true);

        return MilcomReadinessSnapshot::query()->create([
            'recommendation_run_id' => $run->id,
            'nation_id' => $nation->id,
            'role' => $role,
            'alliance_id' => $nation->alliance_id,
            'alliance_position' => $nation->alliance_position,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'vacation_turns' => $overrides['vacation_turns'] ?? $nation->vacation_mode_turns,
            'beige_turns' => $overrides['beige_turns'] ?? $nation->beige_turns,
            'active_offensive_wars' => $overrides['active_offensive_wars'] ?? 0,
            'reserved_offensive_slots' => $overrides['reserved_offensive_slots'] ?? 0,
            'soldiers' => $military['soldiers'],
            'tanks' => $military['tanks'],
            'aircraft' => $military['aircraft'],
            'ships' => $military['ships'],
            'missiles' => $military['missiles'],
            'nukes' => $military['nukes'],
            'last_active_at' => $lastActiveAt,
            'fetched_at' => $fetchedAt,
            'completeness_percent' => 100,
            'payload' => [
                'nation_id' => $nation->id,
                'alliance_id' => $nation->alliance_id,
                'alliance_position' => $nation->alliance_position,
                'score' => (float) $nation->score,
                'cities' => (int) $nation->num_cities,
                'vacation_turns' => (int) ($overrides['vacation_turns'] ?? $nation->vacation_mode_turns),
                'beige_turns' => (int) ($overrides['beige_turns'] ?? $nation->beige_turns),
                'active_offensive_wars' => (int) ($overrides['active_offensive_wars'] ?? 0),
                'reserved_offensive_slots' => (int) ($overrides['reserved_offensive_slots'] ?? 0),
                'military' => $military,
                'last_active_at' => $lastActiveAt?->toIso8601String(),
                'fetched_at' => $fetchedAt->toIso8601String(),
                'discord_linked' => $discordLinked,
                'projects' => $projects,
            ],
        ]);
    }

    protected function createWar(
        int $id,
        Nation $attacker,
        Nation $defender,
        array $attributes = [],
    ): War {
        return War::query()->create(array_merge([
            'id' => $id,
            'date' => now(),
            'reason' => 'Milcom test war',
            'war_type' => 'ORDINARY',
            'turns_left' => 60,
            'att_id' => $attacker->id,
            'att_alliance_id' => $attacker->alliance_id,
            'att_alliance_position' => $attacker->alliance_position,
            'def_id' => $defender->id,
            'def_alliance_id' => $defender->alliance_id,
            'def_alliance_position' => $defender->alliance_position,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    protected function incomingWarPayload(int $id, Nation $aggressor, Nation $attacked): array
    {
        return [
            'id' => $id,
            'date' => now()->toDateTimeString(),
            'reason' => 'Incoming defensive war',
            'war_type' => 'ORDINARY',
            'turns_left' => 60,
            'att_id' => $aggressor->id,
            'att_alliance_id' => $aggressor->alliance_id,
            'att_alliance_position' => $aggressor->alliance_position,
            'def_id' => $attacked->id,
            'def_alliance_id' => $attacked->alliance_id,
            'def_alliance_position' => $attacked->alliance_position,
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function postIncomingWar(array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer testing-nexus-token')
            ->postJson('/api/v1/subs/war/create', $payload);
    }
}
