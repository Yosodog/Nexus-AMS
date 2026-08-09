<?php

namespace Tests\Feature\API;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Http\Controllers\API\Discord\MilcomProjectionController;
use App\Http\Middleware\RequireMilcomV2;
use App\Http\Middleware\ResolveDiscordActor;
use App\Http\Middleware\ValidateDiscordBotAPI;
use App\Http\Middleware\VerifyDiscordInteraction;
use App\Models\DiscordAccount;
use App\Models\MilcomAssignment;
use App\Models\MilcomObjective;
use App\Models\Nation;
use App\Models\User;
use App\Services\Discord\DiscordMilcomReadProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class DiscordMilcomProjectionApiTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const ACTOR_DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private const MANAGER_DISCORD_ID = '345678901234567890';

    private const OTHER_DISCORD_ID = '456789012345678901';

    private Nation $actorNation;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'app.url' => 'https://nexus.test',
            'milcom.v2_enabled' => true,
            'services.discord_bot_key' => 'milcom-projection-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);

        Route::prefix('api/v1/discord/milcom')
            ->middleware([
                'api',
                ValidateDiscordBotAPI::class,
                RequireMilcomV2::class,
                VerifyDiscordInteraction::class,
                ResolveDiscordActor::class,
            ])
            ->group(function (): void {
                Route::get('/assignments', [MilcomProjectionController::class, 'assignments']);
                Route::get('/readiness', [MilcomProjectionController::class, 'readiness']);
                Route::get('/war-rooms/{objective}', [MilcomProjectionController::class, 'warRoom']);
            });

        $this->actorNation = Nation::factory()->create();
        $this->actor = User::factory()->verified()->create(['nation_id' => $this->actorNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::ACTOR_DISCORD_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_current_assignment_list_is_scoped_to_the_actor_and_only_exposes_live_assignments(): void
    {
        $target = Nation::factory()->create();
        $otherNation = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $currentObjective = $this->createMilcomObjective($operation, $target, [
            'status' => ObjectiveStatus::Dispatched,
            'discord_channel_id' => '567890123456789012',
        ]);
        $assignment = $this->createAssignment($currentObjective, $this->actorNation, [
            'status' => AssignmentStatus::Dispatched,
        ]);
        $war = $this->createWar(700000, $this->actorNation, $target);
        $assignment->forceFill(['declared_war_id' => $war->id])->save();
        $this->createAssignment($currentObjective, $otherNation, [
            'rank' => 2,
            'status' => AssignmentStatus::Dispatched,
        ]);

        $proposedObjective = $this->createMilcomObjective($operation, Nation::factory()->create());
        $this->createAssignment($proposedObjective, $this->actorNation, [
            'status' => AssignmentStatus::Proposed,
        ]);

        $completedOperation = $this->createMilcomOperation(['status' => OperationStatus::Completed]);
        $completedObjective = $this->createMilcomObjective($completedOperation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Completed,
        ]);
        $this->createAssignment($completedObjective, $this->actorNation, [
            'status' => AssignmentStatus::Completed,
        ]);

        $response = $this->withHeaders($this->headers('assignments', '567890123456789013'))
            ->getJson('/api/v1/discord/milcom/assignments');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.provider', 'nexus_milcom_v2')
            ->assertJsonPath('meta.projection_schema_version', 1)
            ->assertJsonPath('meta.actor_scope', 'actor_current_assignments')
            ->assertJsonPath('data.0.assignment_id', $assignment->id)
            ->assertJsonPath('data.0.status', AssignmentStatus::Dispatched->value)
            ->assertJsonPath('data.0.target.id', $target->id)
            ->assertJsonPath('data.0.war.id', $war->id)
            ->assertJsonPath('data.0.room.discord_channel_id', '567890123456789012')
            ->assertJsonMissingPath('data.0.friendly_nation_id')
            ->assertJsonMissingPath('data.0.score')
            ->assertJsonMissingPath('data.0.confidence')
            ->assertJsonMissingPath('data.0.factor_explanations')
            ->assertJsonMissingPath('data.0.operation.metadata');

        $this->withHeaders($this->headers('assignments', '567890123456789014'))
            ->getJson("/api/v1/discord/milcom/assignments?nation_id={$otherNation->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.nation_id.0', 'The nation id field is prohibited.');
    }

    public function test_readiness_defaults_to_self_and_requires_war_authority_for_another_nation(): void
    {
        $otherNation = Nation::factory()->create();
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, $otherNation);
        $this->attachSuccessfulRecommendation(
            $objective,
            [$this->actorNation],
            [$this->actorNation->id => [
                'active_offensive_wars' => 2,
                'reserved_offensive_slots' => 1,
            ]],
            ['military' => ['soldiers' => 80_123]],
        );
        $this->createAssignment($objective, $this->actorNation, [
            'status' => AssignmentStatus::Approved,
        ]);
        $this->createWar(700001, $this->actorNation, $otherNation);

        $self = $this->withHeaders($this->headers('readiness', '567890123456789015'))
            ->getJson('/api/v1/discord/milcom/readiness');

        $self->assertOk()
            ->assertJsonPath('meta.actor_scope', 'actor_self')
            ->assertJsonPath('data.nation.id', $this->actorNation->id)
            ->assertJsonPath('data.offensive_slots.active_wars_at_snapshot', 2)
            ->assertJsonPath('data.offensive_slots.reserved_at_snapshot', 1)
            ->assertJsonPath('data.military.soldiers', 150000)
            ->assertJsonMissingPath('data.discord_linked')
            ->assertJsonMissingPath('data.projects')
            ->assertJsonMissingPath('data.recommendation_run_id')
            ->assertJsonMissingPath('data.snapshot.payload');

        $this->withHeaders($this->headers('readiness', '567890123456789030'))
            ->getJson("/api/v1/discord/milcom/readiness?nation_id={$this->actorNation->id}")
            ->assertOk()
            ->assertJsonPath('meta.actor_scope', 'actor_self');

        $this->withHeaders($this->headers('readiness', '567890123456789016'))
            ->getJson("/api/v1/discord/milcom/readiness?nation_id={$otherNation->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->withHeaders($this->headers('readiness', '567890123456789027'))
            ->getJson('/api/v1/discord/milcom/readiness?nation_id=999999999')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->actor = $this->grantPermissions($this->actor, ['view-wars']);

        $this->withHeaders($this->headers('readiness', '567890123456789017'))
            ->getJson("/api/v1/discord/milcom/readiness?nation_id={$otherNation->id}")
            ->assertOk()
            ->assertJsonPath('meta.actor_scope', 'authorized_nation')
            ->assertJsonPath('data.nation.id', $otherNation->id)
            ->assertJsonPath('data.military.soldiers', 80123);

        $this->withHeaders($this->headers('readiness', '567890123456789018'))
            ->getJson('/api/v1/discord/milcom/readiness?nation_id=999999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'readiness_not_found');

        $manager = $this->grantPermissions($this->linkedUser(self::MANAGER_DISCORD_ID), ['manage-war-room']);
        $manager->refresh();

        $this->withHeaders($this->headers('readiness', '567890123456789031', self::MANAGER_DISCORD_ID))
            ->getJson("/api/v1/discord/milcom/readiness?nation_id={$otherNation->id}")
            ->assertOk()
            ->assertJsonPath('data.nation.id', $otherNation->id);
    }

    public function test_war_room_summary_is_limited_to_participants_or_war_room_managers(): void
    {
        [$objective, $assignment] = $this->warRoomFixture();

        $this->assertTrue(MilcomAssignment::query()
            ->whereKey($assignment->id)
            ->where('objective_id', $objective->id)
            ->where('friendly_nation_id', $this->actorNation->id)
            ->where('status', AssignmentStatus::Dispatched->value)
            ->exists());
        $this->assertInstanceOf(
            MilcomObjective::class,
            app(DiscordMilcomReadProvider::class)->warRoom($this->actor->fresh(), (int) $objective->id),
        );

        $participant = $this->withHeaders($this->headers('war-room', '567890123456789019'))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}");

        $participant->assertOk()
            ->assertJsonPath('meta.actor_scope', 'participant_or_manager')
            ->assertJsonPath('data.objective_id', $objective->id)
            ->assertJsonPath('data.discord_channel_id', '678901234567890123')
            ->assertJsonPath('data.assigned_members.0.assignment_id', $assignment->id)
            ->assertJsonPath('data.assigned_members.0.nation.id', $this->actorNation->id)
            ->assertJsonMissingPath('data.assigned_members.0.match_score')
            ->assertJsonMissingPath('data.assigned_members.0.discord_id')
            ->assertJsonMissingPath('data.dispatch')
            ->assertJsonMissingPath('data.recommendation');

        $other = $this->linkedUser(self::OTHER_DISCORD_ID);

        $this->withHeaders($this->headers('war-room', '567890123456789020', self::OTHER_DISCORD_ID))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->withHeaders($this->headers('war-room', '567890123456789028', self::OTHER_DISCORD_ID))
            ->getJson('/api/v1/discord/milcom/war-rooms/999999999')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $manager = $this->grantPermissions($other, ['manage-war-room']);
        $manager->refresh();

        $this->withHeaders($this->headers('war-room', '567890123456789021', self::OTHER_DISCORD_ID))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}")
            ->assertOk()
            ->assertJsonPath('data.objective_id', $objective->id);

        $this->withHeaders($this->headers('war-room', '567890123456789029', self::OTHER_DISCORD_ID))
            ->getJson('/api/v1/discord/milcom/war-rooms/999999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'war_room_not_found');

        $this->withHeaders($this->headers('war-room', '567890123456789022'))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}?objective_id={$objective->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error.details.objective_id.0', 'The objective id field is prohibited.');
    }

    public function test_released_or_merely_proposed_nations_cannot_read_a_war_room(): void
    {
        [$objective, $assignment] = $this->warRoomFixture();

        $assignment->forceFill(['status' => AssignmentStatus::Released])->save();

        $this->withHeaders($this->headers('war-room', '567890123456789023'))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}")
            ->assertForbidden();

        $assignment->forceFill(['status' => AssignmentStatus::Proposed])->save();

        $this->withHeaders($this->headers('war-room', '567890123456789024'))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}")
            ->assertForbidden();
    }

    public function test_authorized_actor_receives_not_found_until_a_room_is_attached(): void
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Approved,
            'discord_channel_id' => null,
        ]);
        $this->createAssignment($objective, $this->actorNation, [
            'status' => AssignmentStatus::Approved,
        ]);

        $this->assertNull(app(DiscordMilcomReadProvider::class)->warRoom(
            $this->actor->fresh(),
            (int) $objective->id,
        ));

        $this->withHeaders($this->headers('war-room', '567890123456789025'))
            ->getJson("/api/v1/discord/milcom/war-rooms/{$objective->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'war_room_not_found');
    }

    /** @return array{MilcomObjective, MilcomAssignment} */
    private function warRoomFixture(): array
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Dispatched,
            'discord_channel_id' => '678901234567890123',
        ]);
        $assignment = $this->createAssignment($objective, $this->actorNation, [
            'status' => AssignmentStatus::Dispatched,
        ]);

        return [$objective, $assignment];
    }

    private function linkedUser(string $discordId): User
    {
        $user = User::factory()->verified()->create([
            'nation_id' => Nation::factory()->create()->id,
        ]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => $discordId,
            'unlinked_at' => null,
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(string $command, string $interactionId, string $discordId = self::ACTOR_DISCORD_ID): array
    {
        return $this->signedDiscordInteractionHeaders(
            'milcom-projection-test-key',
            self::GUILD_ID,
            $discordId,
            $interactionId,
            "milcom.{$command}",
        );
    }
}
