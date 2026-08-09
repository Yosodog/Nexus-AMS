<?php

namespace Tests\Feature\Milcom;

use App\Domain\Federation\Services\FederationOperationGuard;
use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Jobs\SendMilcomAssignmentMessageJob;
use App\Models\Alliance;
use App\Models\MilcomAssignmentDelivery;
use App\Models\MilcomObjective;
use App\Models\MilcomOperation;
use App\Models\Nation;
use App\Services\Milcom\AssignmentDeliveryService;
use App\Services\PWMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class MilcomAssignmentDeliveryTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('milcom.v2_enabled', true);
    }

    public function test_active_wave_queues_each_assignment_once_on_the_default_queue(): void
    {
        Queue::fake();
        [$operation] = $this->activeWaveWithTeam();
        $this->authenticateMilcomManager();
        $endpoint = "/api/v1/milcom/operations/{$operation->id}/deliver-in-game";

        $this->postJson($endpoint, ['generation_version' => 1])
            ->assertAccepted()
            ->assertJsonPath('data.deliveries.queued', 2)
            ->assertJsonPath('data.deliveries.already_queued', 0)
            ->assertJsonPath('data.deliveries.already_sent', 0);

        $this->assertDatabaseCount('milcom_assignment_deliveries', 2);
        Queue::assertPushed(SendMilcomAssignmentMessageJob::class, 2);
        Queue::assertPushed(
            SendMilcomAssignmentMessageJob::class,
            fn (SendMilcomAssignmentMessageJob $job): bool => $job->queue === null,
        );

        $this->postJson($endpoint, ['generation_version' => 1])
            ->assertAccepted()
            ->assertJsonPath('data.deliveries.queued', 0)
            ->assertJsonPath('data.deliveries.already_queued', 2);

        $this->assertDatabaseCount('milcom_assignment_deliveries', 2);
        Queue::assertPushed(SendMilcomAssignmentMessageJob::class, 2);
    }

    public function test_in_game_message_links_the_target_and_every_teammate(): void
    {
        Queue::fake();
        [$operation, $objective, $friendlies] = $this->activeWaveWithTeam();
        $target = $objective->target;
        $baselineTransactionLevel = DB::transactionLevel();
        $this->mock(PWMessageService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (int $nationId, string $subject, string $message) use ($target, $friendlies, $baselineTransactionLevel): bool {
                $this->assertSame($baselineTransactionLevel, DB::transactionLevel());
                $this->assertSame($friendlies[0]->id, $nationId);
                $this->assertStringContainsString('War target:', $subject);
                $this->assertStringContainsString(
                    "[link=https://politicsandwar.com/nation/id={$target->id}]{$target->nation_name}[/link]",
                    $message,
                );

                foreach ($friendlies as $friendly) {
                    $this->assertStringContainsString(
                        "[link=https://politicsandwar.com/nation/id={$friendly->id}]{$friendly->nation_name}[/link]",
                        $message,
                    );
                }

                $this->assertStringContainsString('Open this wave in '.config('app.name').'[/link]', $message);

                return true;
            })
            ->andReturn(true);
        $this->authenticateMilcomManager();

        $this->postJson("/api/v1/milcom/operations/{$operation->id}/deliver-in-game", [
            'generation_version' => 1,
        ])->assertAccepted();

        $delivery = MilcomAssignmentDelivery::query()
            ->whereHas('assignment', fn ($query) => $query
                ->where('friendly_nation_id', $friendlies[0]->id))
            ->firstOrFail();
        app(AssignmentDeliveryService::class)->deliver($delivery->id);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_interrupted_sending_lease_is_not_automatically_sent_twice(): void
    {
        Queue::fake();
        [$operation] = $this->activeWaveWithTeam();
        $this->authenticateMilcomManager();
        $this->postJson("/api/v1/milcom/operations/{$operation->id}/deliver-in-game", [
            'generation_version' => 1,
        ])->assertAccepted();
        $delivery = MilcomAssignmentDelivery::query()->firstOrFail();
        $delivery->forceFill(['status' => 'sending'])->save();
        $deliveries = $this->mock(AssignmentDeliveryService::class);
        $deliveries->shouldNotReceive('deliver');

        (new SendMilcomAssignmentMessageJob($delivery->id))->handle(
            $deliveries,
            app(FederationOperationGuard::class),
        );

        $delivery = $delivery->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('uncertain', $delivery->last_error);
        $this->assertNull($delivery->sent_at);
    }

    /** @return array{0: MilcomOperation, 1: MilcomObjective, 2: list<Nation>} */
    private function activeWaveWithTeam(): array
    {
        $friendlyAlliance = Alliance::factory()->create();
        $enemyAlliance = Alliance::factory()->create();
        $friendlies = Nation::factory()->count(2)->create([
            'alliance_id' => $friendlyAlliance->id,
        ])->all();
        $target = Nation::factory()->create(['alliance_id' => $enemyAlliance->id]);
        $operation = $this->createMilcomOperation([
            'status' => OperationStatus::Active,
            'current_stage' => 'live',
            'metadata' => ['wave' => 2, 'finalized_at' => now()->toIso8601String()],
        ]);
        $this->addFriendlyScope($operation, $friendlyAlliance);
        $objective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Approved,
            'desired_team_depth' => 2,
            'minimum_team_depth' => 1,
            'approved_at' => now(),
        ]);

        foreach ($friendlies as $rank => $friendly) {
            $this->createAssignment($objective, $friendly, [
                'status' => AssignmentStatus::Approved,
                'rank' => $rank + 1,
                'approved_at' => now(),
            ]);
        }

        return [$operation, $objective->fresh('target'), $friendlies];
    }
}
