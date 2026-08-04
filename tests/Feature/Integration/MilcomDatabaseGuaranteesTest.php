<?php

namespace Tests\Feature\Integration;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\DispatchStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Exceptions\MilcomPreflightException;
use App\Models\Alliance;
use App\Models\MilcomAssignment;
use App\Models\MilcomDispatch;
use App\Models\MilcomIncident;
use App\Models\MilcomObjective;
use App\Models\Nation;
use App\Models\User;
use App\Services\Milcom\ApprovalService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\Integration\MySqlIntegrationTestCase;
use Throwable;

class MilcomDatabaseGuaranteesTest extends MySqlIntegrationTestCase
{
    use BuildsMilcomFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
        config()->set('milcom.game_rules.base_offensive_slots', 1);
        config()->set('milcom.game_rules.offensive_slot_projects', []);
    }

    public function test_incident_war_id_is_unique(): void
    {
        $aggressor = Nation::factory()->create();
        $attacked = Nation::factory()->create();
        $war = $this->createWar(94_001, $aggressor, $attacked);
        $attributes = [
            'war_id' => $war->id,
            'attacked_nation_id' => $attacked->id,
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'new',
            'detected_at' => now(),
        ];

        MilcomIncident::query()->create($attributes);

        $this->assertUniqueConstraintViolation(
            fn () => MilcomIncident::query()->create($attributes)
        );
    }

    public function test_objective_and_assignment_pairs_are_unique(): void
    {
        $operation = $this->createMilcomOperation();
        $target = Nation::factory()->create();
        $friendly = Nation::factory()->create();
        $objective = $this->createMilcomObjective($operation, $target);
        $assignment = $this->createAssignment($objective, $friendly);

        $this->assertUniqueConstraintViolation(
            fn () => $this->createMilcomObjective($operation, $target)
        );
        $this->assertUniqueConstraintViolation(
            fn () => MilcomAssignment::query()->create([
                ...$assignment->only([
                    'objective_id',
                    'friendly_nation_id',
                    'score',
                    'confidence',
                    'rank',
                    'status',
                    'is_locked',
                ]),
            ])
        );
    }

    public function test_nullable_open_key_allows_history_but_only_one_open_counter_per_target(): void
    {
        $target = Nation::factory()->create();
        $open = $this->createMilcomObjective(
            $this->createMilcomOperation(),
            $target,
            ['open_key' => 1]
        );
        $this->createMilcomObjective($this->createMilcomOperation(), $target, [
            'status' => ObjectiveStatus::Completed,
            'open_key' => null,
        ]);
        $this->createMilcomObjective($this->createMilcomOperation(), $target, [
            'status' => ObjectiveStatus::Cancelled,
            'open_key' => null,
        ]);

        $this->assertSame(2, MilcomObjective::query()
            ->where('target_nation_id', $target->id)
            ->whereNull('open_key')
            ->count());
        $this->assertUniqueConstraintViolation(fn () => $this->createMilcomObjective(
            $this->createMilcomOperation(),
            $target,
            ['open_key' => 1]
        ));
        $this->assertSame(1, $open->open_key);
    }

    public function test_dispatch_dedupe_key_is_unique(): void
    {
        $operation = $this->createMilcomOperation();
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $attributes = [
            'operation_id' => $operation->id,
            'objective_id' => $objective->id,
            'dispatch_version' => 1,
            'status' => DispatchStatus::Pending,
            'dedupe_key' => "milcom-objective:{$objective->id}:room:v1",
            'payload_snapshot' => [],
        ];

        MilcomDispatch::query()->create($attributes);

        $this->assertUniqueConstraintViolation(
            fn () => MilcomDispatch::query()->create($attributes)
        );
    }

    public function test_concurrent_approvals_cannot_oversubscribe_a_friendly_nation(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent Milcom approval testing requires the pcntl extension.');
        }

        $friendlyAlliance = Alliance::factory()->create();
        $friendly = Nation::factory()->create([
            'alliance_id' => $friendlyAlliance->id,
            'alliance_position' => 'MEMBER',
            'score' => 1_000,
            'vacation_mode_turns' => 0,
            'beige_turns' => 0,
        ]);
        $actor = User::factory()->create();
        $objectiveIds = [];

        foreach (range(1, 2) as $index) {
            $target = Nation::factory()->create([
                'score' => 1_000 + $index,
                'vacation_mode_turns' => 0,
                'beige_turns' => 0,
            ]);
            $operation = $this->createMilcomOperation();
            $this->addFriendlyScope($operation, $friendlyAlliance);
            $objective = $this->createMilcomObjective($operation, $target, [
                'status' => ObjectiveStatus::Review,
                'desired_team_depth' => 1,
                'minimum_team_depth' => 1,
            ]);
            $run = $this->attachSuccessfulRecommendation($objective, [$friendly]);
            $this->createAssignment($objective, $friendly, [
                'status' => AssignmentStatus::Proposed,
                'recommendation_run_id' => $run->id,
            ]);
            $objectiveIds[] = (int) $objective->id;
        }

        $results = $this->runConcurrently(array_map(
            fn (int $objectiveId): Closure => fn (): array => $this->approvalResult($objectiveId, (int) $actor->id),
            $objectiveIds,
        ));

        $this->assertCount(1, collect($results)->where('status', 'ok'));
        $this->assertCount(1, collect($results)->where('status', 'blocked'));
        $this->assertContains(
            'no_offensive_slot',
            collect($results)->firstWhere('status', 'blocked')['blocker_codes'] ?? []
        );
        $this->assertSame(1, MilcomAssignment::query()
            ->where('friendly_nation_id', $friendly->id)
            ->where('status', AssignmentStatus::Approved->value)
            ->count());
        $this->assertSame(1, DB::table('milcom_nation_capacity_locks')
            ->where('friendly_nation_id', $friendly->id)
            ->count());
    }

    /** @return array{status: string, blocker_codes?: list<string>} */
    private function approvalResult(int $objectiveId, int $actorUserId): array
    {
        try {
            app(ApprovalService::class)->approve(
                MilcomObjective::query()->findOrFail($objectiveId),
                1,
                $actorUserId,
            );

            return ['status' => 'ok'];
        } catch (MilcomPreflightException $exception) {
            return [
                'status' => 'blocked',
                'blocker_codes' => array_values(array_filter(array_column($exception->blockers, 'code'))),
            ];
        }
    }

    /** @param list<Closure(): array<string, mixed>> $workers */
    private function runConcurrently(array $workers): array
    {
        $basePath = sys_get_temp_dir().'/nexus-milcom-'.Str::uuid();
        $gatePath = $basePath.'.gate';
        $resultPaths = [];
        $processes = [];

        DB::disconnect('mysql');
        DB::purge('mysql');

        foreach ($workers as $index => $worker) {
            $resultPath = $basePath.'.'.$index.'.json';
            $resultPaths[] = $resultPath;
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Unable to fork a Milcom approval worker.');
            }

            if ($processId === 0) {
                while (! is_file($gatePath)) {
                    usleep(1_000);
                    clearstatcache(true, $gatePath);
                }

                DB::purge('mysql');
                DB::reconnect('mysql');

                try {
                    $result = $worker();
                } catch (Throwable $exception) {
                    $result = [
                        'status' => 'error',
                        'class' => $exception::class,
                        'message' => $exception->getMessage(),
                    ];
                }

                file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
                exit(0);
            }

            $processes[] = $processId;
        }

        file_put_contents($gatePath, 'go');

        foreach ($processes as $processId) {
            pcntl_waitpid($processId, $status);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException("Milcom approval worker [{$processId}] failed.");
            }
        }

        DB::purge('mysql');
        DB::reconnect('mysql');

        try {
            return array_map(function (string $resultPath): array {
                if (! is_file($resultPath)) {
                    throw new RuntimeException("Milcom approval result [{$resultPath}] was not written.");
                }

                return json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
            }, $resultPaths);
        } finally {
            @unlink($gatePath);

            foreach ($resultPaths as $resultPath) {
                @unlink($resultPath);
            }
        }
    }

    private function assertUniqueConstraintViolation(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the Milcom database constraint to reject a duplicate row.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', (string) ($exception->errorInfo[0] ?? ''));
        }
    }
}
