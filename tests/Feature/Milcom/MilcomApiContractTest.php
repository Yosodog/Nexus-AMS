<?php

namespace Tests\Feature\Milcom;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use App\Domain\Milcom\Enums\PriorityTier;
use App\Models\Alliance;
use App\Models\MilcomObjectiveRecommendation;
use App\Models\Nation;
use App\Services\Milcom\MilcomQueryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomApiContractTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
    }

    public function test_v2_api_is_unavailable_when_the_feature_flag_is_disabled(): void
    {
        $this->authenticateMilcomManager();
        config()->set('milcom.v1_enabled', true);
        config()->set('milcom.v2_requested', false);
        config()->set('milcom.v2_enabled', false);

        $this->getJson('/api/v1/milcom/dashboard')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'milcom_v2_disabled');
    }

    public function test_operational_reads_and_commands_require_manage_war_room(): void
    {
        $viewer = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($viewer);
        $this->actingAsSanctum($viewer);

        $this->getJson('/api/v1/milcom/dashboard')->assertForbidden();
        $this->postJson('/api/v1/milcom/operations', [
            'name' => 'Unauthorized plan',
        ])->assertForbidden();
        $this->assertDatabaseCount('milcom_operations', 0);

        $this->authenticateMilcomManager();

        $this->getJson('/api/v1/milcom/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['summary', 'exceptions', 'operations'], 'meta', 'links']);
    }

    public function test_alliance_picker_search_returns_compact_ranked_results(): void
    {
        $alliance = Alliance::factory()->create([
            'name' => 'The Knights Radiant',
            'acronym' => 'TKR',
            'rank' => 3,
            'flag' => 'https://example.test/tkr.png',
        ]);
        Alliance::factory()->create([
            'name' => 'Knights of the Round Table',
            'acronym' => 'KRT',
            'rank' => 30,
        ]);
        Nation::factory()->count(2)->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'APPLICANT',
        ]);
        $this->authenticateMilcomManager();
        $allianceSearchSql = null;
        DB::listen(function (QueryExecuted $query) use (&$allianceSearchSql): void {
            if (str_contains($query->sql, 'member_count')
                && str_contains($query->sql, 'alliances')) {
                $allianceSearchSql = $query->sql;
            }
        });

        $response = $this->getJson('/api/v1/milcom/alliances?q=TKR&limit=12')
            ->assertOk()
            ->assertJsonPath('data.alliances.0.id', $alliance->id)
            ->assertJsonPath('data.alliances.0.name', 'The Knights Radiant')
            ->assertJsonPath('data.alliances.0.acronym', 'TKR')
            ->assertJsonPath('data.alliances.0.member_count', 2)
            ->assertJsonPath('data.alliances.0.url', 'https://politicsandwar.com/alliance/id='.$alliance->id);

        $this->assertEqualsCanonicalizing([
            'id',
            'name',
            'acronym',
            'flag',
            'rank',
            'score',
            'average_score',
            'member_count',
            'url',
        ], array_keys($response->json('data.alliances.0')));
        $this->assertLessThan(10_000, strlen($response->getContent()));
        $this->assertNotNull($allianceSearchSql);
        $this->assertStringContainsString(
            'CASE WHEN `rank` IS NULL OR `rank` <= 0',
            $allianceSearchSql,
        );

        $this->getJson('/api/v1/milcom/alliances?ids='.$alliance->id)
            ->assertOk()
            ->assertJsonPath('data.alliances.0.id', $alliance->id);
    }

    public function test_scope_rejects_an_alliance_added_to_both_sides(): void
    {
        $alliance = Alliance::factory()->create();
        $operation = $this->createMilcomOperation();
        $this->authenticateMilcomManager();

        $this->putJson("/api/v1/milcom/operations/{$operation->id}/scope", [
            'generation_version' => 1,
            'friendly_alliance_ids' => [$alliance->id],
            'enemy_alliance_ids' => [$alliance->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['friendly_alliance_ids', 'enemy_alliance_ids']);

        $this->assertDatabaseMissing('milcom_operation_alliances', [
            'operation_id' => $operation->id,
            'alliance_id' => $alliance->id,
        ]);
    }

    public function test_objective_list_uses_an_allowlisted_compact_nation_shape(): void
    {
        $enemyAlliance = Alliance::factory()->create();
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'discord' => 'sensitive-discord-name',
            'discord_id' => '123456789012345678',
            'projects' => 123456,
            'project_bits' => '111111111111111111',
        ]);
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $target);
        $friendly = Nation::factory()->create();
        $this->createAssignment($objective, $friendly);
        $this->authenticateMilcomManager();

        $response = $this->getJson(
            "/api/v1/milcom/operations/{$operation->id}/objectives?filter=all&limit=50"
        );

        $response->assertOk()
            ->assertJsonPath('data.objectives.0.id', $objective->id)
            ->assertJsonPath('data.objectives.0.target.id', $target->id)
            ->assertJsonPath('data.objectives.0.assigned_nations.0.id', $friendly->id)
            ->assertJsonPath('data.objectives.0.assigned_nations.0.nation_name', $friendly->nation_name)
            ->assertJsonCount(1, 'data.objectives');

        $nation = $response->json('data.objectives.0.target');
        $this->assertEqualsCanonicalizing([
            'id',
            'nation_name',
            'leader_name',
            'alliance_id',
            'alliance',
            'score',
            'num_cities',
            'cities',
            'beige_turns',
            'vacation_mode_turns',
            'soldiers',
            'tanks',
            'aircraft',
            'ships',
        ], array_keys($nation));
        $this->assertForbiddenJsonKeysAreAbsent($response->json());
        $this->assertLessThan(20_000, strlen($response->getContent()));
    }

    public function test_objective_detail_uses_recommendation_snapshot_military_for_target_and_teams(): void
    {
        $friendly = Nation::factory()->create();
        $alternative = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->attachSuccessfulRecommendation(
            $objective,
            [$friendly, $alternative],
            [
                $friendly->id => ['military' => [
                    'soldiers' => 155_000,
                    'tanks' => 11_000,
                    'aircraft' => 1_300,
                    'ships' => 130,
                ]],
                $alternative->id => ['military' => [
                    'soldiers' => 145_000,
                    'tanks' => 9_500,
                    'aircraft' => 1_150,
                    'ships' => 110,
                ]],
            ],
            ['military' => [
                'soldiers' => 82_000,
                'tanks' => 5_200,
                'aircraft' => 710,
                'ships' => 61,
            ]],
        );
        $this->createAssignment($objective, $friendly, [
            'recommendation_run_id' => $run->id,
        ]);
        MilcomObjectiveRecommendation::query()->create([
            'recommendation_run_id' => $run->id,
            'objective_id' => $objective->id,
            'team_score' => 88,
            'confidence' => 100,
            'proposed_team' => [
                'nation_ids' => [$friendly->id],
                'score' => 88,
                'partial' => true,
            ],
            'alternatives' => [[
                'nation_ids' => [$alternative->id],
                'score' => 84,
                'partial' => true,
            ]],
            'blocker_summary' => [],
            'factor_explanations' => [
                'members' => [],
                'warnings' => [[
                    'code' => 'missing_discord_link',
                    'message' => 'This nation has no linked Discord account.',
                    'context' => ['nation_id' => $friendly->id],
                ]],
            ],
        ]);
        $this->authenticateMilcomManager();

        $this->getJson("/api/v1/milcom/objectives/{$objective->id}")
            ->assertOk()
            ->assertJsonPath('data.objective.target.soldiers', 82_000)
            ->assertJsonPath('data.objective.target.tanks', 5_200)
            ->assertJsonPath('data.objective.target.aircraft', 710)
            ->assertJsonPath('data.objective.target.ships', 61)
            ->assertJsonPath('data.objective.assignments.0.friendly.soldiers', 155_000)
            ->assertJsonPath('data.objective.assignments.0.friendly.tanks', 11_000)
            ->assertJsonPath('data.objective.assignments.0.friendly.aircraft', 1_300)
            ->assertJsonPath('data.objective.assignments.0.friendly.ships', 130)
            ->assertJsonPath('data.objective.recommendation.warnings.0.code', 'missing_discord_link')
            ->assertJsonPath('data.objective.recommendation.warnings.0.context.nation_id', $friendly->id)
            ->assertJsonPath('data.objective.recommendation.warnings.0.context.nation_name', $friendly->nation_name)
            ->assertJsonPath('data.objective.recommendation.alternatives.0.team.0.soldiers', 145_000)
            ->assertJsonPath('data.objective.recommendation.alternatives.0.team.0.tanks', 9_500)
            ->assertJsonPath('data.objective.recommendation.alternatives.0.team.0.aircraft', 1_150)
            ->assertJsonPath('data.objective.recommendation.alternatives.0.team.0.ships', 110);

        $this->getJson("/api/v1/milcom/operations/{$operation->id}/objectives?filter=all")
            ->assertOk()
            ->assertJsonPath('data.objectives.0.warning_count', 1)
            ->assertJsonPath('data.objectives.0.warning_summary', '1 assigned nation has no linked Discord account.');
    }

    public function test_counter_detail_hides_a_stale_alternative_containing_the_attacked_nation(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $assigned = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $attacked = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
        ]);
        $operation = $this->createMilcomOperation(['type' => OperationType::Counter]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->attachSuccessfulRecommendation($objective, [$assigned, $attacked]);
        $this->createAssignment($objective, $assigned, ['recommendation_run_id' => $run->id]);
        MilcomObjectiveRecommendation::query()->create([
            'recommendation_run_id' => $run->id,
            'objective_id' => $objective->id,
            'team_score' => 80,
            'confidence' => 100,
            'proposed_team' => [
                'nation_ids' => [$assigned->id],
                'score' => 80,
                'partial' => true,
            ],
            'alternatives' => [[
                'nation_ids' => [$attacked->id],
                'score' => 75,
                'partial' => true,
            ]],
            'blocker_summary' => [],
            'factor_explanations' => ['members' => [], 'warnings' => []],
        ]);
        $this->createWar(945_000, $target, $attacked);
        $this->authenticateMilcomManager();

        $this->getJson("/api/v1/milcom/objectives/{$objective->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.objective.recommendation.alternatives');
    }

    public function test_stale_generation_commands_return_machine_readable_conflict(): void
    {
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['generation_version' => 4]);
        $objective = $this->createMilcomObjective($operation, $target, ['generation_version' => 4]);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/objectives/{$objective->id}/approve", [
            'generation_version' => 3,
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'stale_generation')
            ->assertJsonPath('blockers.0.expected_generation', 3)
            ->assertJsonPath('blockers.0.current_generation', 4)
            ->assertJsonPath('meta.generation_version', 4);
    }

    public function test_starting_an_operation_keeps_targets_and_assignments_live(): void
    {
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Review,
            'current_stage' => 'dispatch',
            'approved_at' => now(),
        ]);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Approved,
            'approved_at' => now(),
        ]);
        $assignment = $this->createAssignment($objective, $friendly, [
            'status' => AssignmentStatus::Approved,
            'approved_at' => now(),
        ]);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/activate", [
            'generation_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.operation.status', OperationStatus::Active->value)
            ->assertJsonPath('data.operation.current_stage', 'live')
            ->assertJsonPath('message', 'Wave finalized. The live dashboard is ready.');

        $this->assertSame(OperationStatus::Active, $operation->fresh()->status);
        $this->assertSame(ObjectiveStatus::Approved, $objective->fresh()->status);
        $this->assertSame(AssignmentStatus::Approved, $assignment->fresh()->status);
        $this->assertNull($operation->fresh()->completed_at);
        $this->assertDatabaseHas('milcom_events', [
            'operation_id' => $operation->id,
            'event_type' => 'operation.activated',
        ]);

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/activate", [
            'generation_version' => 1,
        ])->assertOk();

        $this->assertDatabaseCount('milcom_events', 1);
    }

    public function test_finalizing_a_wave_moves_unstaffed_targets_to_hold(): void
    {
        $friendly = Nation::factory()->create();
        $approvedTarget = Nation::factory()->create();
        $unstaffedTarget = Nation::factory()->create();
        $existingHoldTarget = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Review,
            'current_stage' => 'dispatch',
        ]);
        $approvedObjective = $this->createMilcomObjective($operation, $approvedTarget, [
            'status' => ObjectiveStatus::Approved,
            'approved_at' => now(),
        ]);
        $unstaffedObjective = $this->createMilcomObjective($operation, $unstaffedTarget, [
            'status' => ObjectiveStatus::Blocked,
        ]);
        $existingHoldObjective = $this->createMilcomObjective($operation, $existingHoldTarget, [
            'priority_tier' => PriorityTier::Hold,
            'minimum_team_depth' => 0,
            'desired_team_depth' => 0,
        ]);
        $this->createAssignment($approvedObjective, $friendly, [
            'status' => AssignmentStatus::Approved,
            'approved_at' => now(),
        ]);
        $this->authenticateMilcomManager();

        $summary = app(MilcomQueryService::class)->coverageSummary($operation);
        $this->assertSame(1, $summary['needs_attention']);
        $this->assertSame(1, $summary['auto_hold_on_finalize']);
        $this->assertSame(0, $summary['finalize_review_required']);
        $this->getJson("/api/v1/milcom/operations/{$operation->id}/objectives?filter=needs_attention")
            ->assertOk()
            ->assertJsonCount(1, 'data.objectives')
            ->assertJsonPath('data.objectives.0.id', $unstaffedObjective->id);

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/activate", [
            'generation_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.operation.status', OperationStatus::Active->value)
            ->assertJsonPath('message', '1 target without a team was moved to Hold. The live dashboard is ready.');

        $this->assertSame(PriorityTier::Hold, $unstaffedObjective->fresh()->priority_tier);
        $this->assertSame(0, $unstaffedObjective->fresh()->minimum_team_depth);
        $this->assertSame(0, $unstaffedObjective->fresh()->desired_team_depth);
        $this->assertSame(PriorityTier::Hold, $existingHoldObjective->fresh()->priority_tier);
        $this->assertSame(1, data_get($operation->fresh()->metadata, 'auto_held_target_count'));
        $this->assertDatabaseHas('milcom_events', [
            'operation_id' => $operation->id,
            'event_type' => 'operation.unstaffed_targets_held',
        ]);
    }

    public function test_finalizing_still_requires_review_of_a_proposed_team(): void
    {
        $approvedFriendly = Nation::factory()->create();
        $proposedFriendly = Nation::factory()->create();
        $approvedTarget = Nation::factory()->create();
        $proposedTarget = Nation::factory()->create();
        $unstaffedTarget = Nation::factory()->create();
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Review,
            'current_stage' => 'dispatch',
        ]);
        $approvedObjective = $this->createMilcomObjective($operation, $approvedTarget, [
            'status' => ObjectiveStatus::Approved,
            'approved_at' => now(),
        ]);
        $proposedObjective = $this->createMilcomObjective($operation, $proposedTarget);
        $unstaffedObjective = $this->createMilcomObjective($operation, $unstaffedTarget, [
            'status' => ObjectiveStatus::Blocked,
        ]);
        $this->createAssignment($approvedObjective, $approvedFriendly, [
            'status' => AssignmentStatus::Approved,
            'approved_at' => now(),
        ]);
        $this->createAssignment($proposedObjective, $proposedFriendly);
        $this->authenticateMilcomManager();

        $summary = app(MilcomQueryService::class)->coverageSummary($operation);
        $this->assertSame(1, $summary['auto_hold_on_finalize']);
        $this->assertSame(1, $summary['finalize_review_required']);

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/activate", [
            'generation_version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);

        $this->assertSame(OperationStatus::Review, $operation->fresh()->status);
        $this->assertSame(PriorityTier::Standard, $proposedObjective->fresh()->priority_tier);
        $this->assertSame(PriorityTier::Standard, $unstaffedObjective->fresh()->priority_tier);
    }

    public function test_operation_cannot_start_before_a_target_is_approved(): void
    {
        $friendly = Nation::factory()->create();
        $target = Nation::factory()->create();
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, $target);
        $this->createAssignment($objective, $friendly);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/activate", [
            'generation_version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);

        $this->assertSame(OperationStatus::Review, $operation->fresh()->status);
        $this->assertSame(ObjectiveStatus::Review, $objective->fresh()->status);
    }

    public function test_new_wave_uses_the_next_series_number_and_copies_target_scope(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $source = $this->createMilcomOperation([
            'name' => 'Coalition Dawn',
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
        ]);
        $source->forceFill(['metadata' => [
            'wave' => 1,
            'base_name' => 'Coalition Dawn',
            'series_root_id' => $source->id,
            'finalized_at' => now()->toIso8601String(),
        ]])->save();
        $this->addFriendlyScope($source, $friendlyAlliance);
        $source->alliances()->create([
            'alliance_id' => $enemyAlliance->id,
            'role' => 'enemy',
            'included' => true,
        ]);
        $this->createMilcomObjective($source, $target, [
            'status' => ObjectiveStatus::Approved,
        ]);
        $this->createMilcomOperation([
            'name' => 'Coalition Dawn - Wave 2',
            'metadata' => [
                'wave' => 2,
                'base_name' => 'Coalition Dawn',
                'series_root_id' => $source->id,
            ],
        ]);
        $this->authenticateMilcomManager();

        $response = $this->postJson("/api/v1/milcom/operations/{$source->id}/clone", [
            'generation_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.operation.name', 'Coalition Dawn - Wave 3')
            ->assertJsonPath('data.operation.metadata.wave', 3)
            ->assertJsonPath('data.operation.metadata.series_root_id', $source->id)
            ->assertJsonPath('data.operation.status', OperationStatus::Draft->value)
            ->assertJsonPath('data.operation.current_stage', 'scope');

        $copyId = (int) $response->json('data.operation.id');
        $this->assertDatabaseHas('milcom_objectives', [
            'operation_id' => $copyId,
            'target_nation_id' => $target->id,
            'status' => ObjectiveStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('milcom_operation_alliances', [
            'operation_id' => $copyId,
            'alliance_id' => $friendlyAlliance->id,
            'role' => 'friendly',
        ]);
    }

    public function test_finalized_wave_scope_cannot_be_changed(): void
    {
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'metadata' => ['wave' => 1, 'finalized_at' => now()->toIso8601String()],
        ]);
        $this->authenticateMilcomManager();

        $this->putJson("/api/v1/milcom/operations/{$operation->id}/scope", [
            'generation_version' => 1,
            'friendly_alliance_ids' => [Alliance::factory()->create()->id],
            'enemy_alliance_ids' => [Alliance::factory()->create()->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scope']);
    }

    public function test_scope_keeps_temporarily_undeclarable_targets_as_hold_objectives(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        Nation::factory()->create(['alliance_id' => $friendlyAlliance->id]);
        $actionable = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]);
        $vacation = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'vacation_mode_turns' => 12,
            'beige_turns' => 0,
        ]);
        $beige = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'vacation_mode_turns' => 0,
            'beige_turns' => 12,
        ]);
        $operation = $this->createMilcomOperation();
        $this->authenticateMilcomManager();

        $this->putJson("/api/v1/milcom/operations/{$operation->id}/scope", [
            'generation_version' => 1,
            'friendly_alliance_ids' => [$friendlyAlliance->id],
            'enemy_alliance_ids' => [$enemyAlliance->id],
            'priority_overrides' => [$vacation->id => PriorityTier::Critical->value],
        ])->assertOk();

        $this->assertDatabaseCount('milcom_objectives', 3);
        $this->assertDatabaseHas('milcom_objectives', [
            'operation_id' => $operation->id,
            'target_nation_id' => $actionable->id,
            'status' => ObjectiveStatus::Pending->value,
        ]);

        foreach ([$vacation, $beige] as $target) {
            $this->assertDatabaseHas('milcom_objectives', [
                'operation_id' => $operation->id,
                'target_nation_id' => $target->id,
                'priority_tier' => PriorityTier::Hold->value,
                'desired_team_depth' => 0,
                'minimum_team_depth' => 0,
                'status' => ObjectiveStatus::Blocked->value,
            ]);
        }
    }

    public function test_objective_status_filters_match_the_officer_controls(): void
    {
        $operation = $this->createMilcomOperation();
        $blocked = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Blocked,
        ]);
        $approved = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Approved,
        ]);
        $dispatched = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Dispatched,
        ]);
        $engaged = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Engaged,
        ]);
        $finished = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Completed,
        ]);
        $this->authenticateMilcomManager();

        foreach ([
            'blocked' => $blocked->id,
            'approved' => $approved->id,
            'dispatched' => $dispatched->id,
            'engaged' => $engaged->id,
            'finished' => $finished->id,
        ] as $filter => $expectedId) {
            $this->getJson("/api/v1/milcom/operations/{$operation->id}/objectives?filter={$filter}")
                ->assertOk()
                ->assertJsonCount(1, 'data.objectives')
                ->assertJsonPath('data.objectives.0.id', $expectedId);
        }
    }

    public function test_manual_staffing_advances_generation_and_rejects_a_stale_follow_up(): void
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $first = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $second = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'score' => 1_000,
        ]);
        $target = Nation::factory()->create([
            'alliance_id' => $enemyAlliance->id,
            'score' => 1_000,
        ]);
        $operation = $this->createMilcomOperation();
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target);
        $run = $this->attachSuccessfulRecommendation($objective, [$first, $second]);
        $this->authenticateMilcomManager();
        $endpoint = "/api/v1/milcom/objectives/{$objective->id}/assignments/manual";

        $this->postJson($endpoint, [
            'generation_version' => 1,
            'friendly_nation_id' => $first->id,
            'override_reason' => 'Officer selected this nation for a specific tactical role.',
            'lock' => true,
        ])->assertOk()
            ->assertJsonPath('meta.generation_version', 2);

        $this->assertSame(2, $operation->fresh()->generation_version);
        $this->assertSame(2, $objective->fresh()->generation_version);
        $this->assertSame(2, $run->fresh()->generation_version);
        $this->postJson($endpoint, [
            'generation_version' => 1,
            'friendly_nation_id' => $second->id,
            'override_reason' => 'This stale change must not replace the reviewed team.',
            'lock' => true,
        ])->assertConflict()
            ->assertJsonPath('blockers.0.code', 'stale_generation')
            ->assertJsonPath('meta.generation_version', 2);
    }

    private function assertForbiddenJsonKeysAreAbsent(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        $forbidden = [
            'discord',
            'discord_id',
            'projects',
            'project_bits',
            'money',
            'credits',
            'latest_sign_in',
            'factor_explanations',
        ];

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden, "Sensitive or expanded field [{$key}] leaked.");
            }

            $this->assertForbiddenJsonKeysAreAbsent($nested);
        }
    }
}
