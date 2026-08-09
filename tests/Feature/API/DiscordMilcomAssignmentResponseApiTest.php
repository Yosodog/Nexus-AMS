<?php

namespace Tests\Feature\API;

use App\Domain\Milcom\Enums\AssignmentStatus;
use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\OperationStatus;
use App\Models\AuditLog;
use App\Models\DiscordAccount;
use App\Models\DiscordActionIntent;
use App\Models\DiscordAssignmentResponse;
use App\Models\MilcomAssignment;
use App\Models\Nation;
use App\Models\User;
use App\Services\Discord\DiscordMilcomAssignmentResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\Feature\Milcom\Concerns\BuildsMilcomFixtures;
use Tests\TestCase;

class DiscordMilcomAssignmentResponseApiTest extends TestCase
{
    use BuildsMilcomFixtures;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const ACTOR_DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private const OTHER_DISCORD_ID = '345678901234567890';

    private Nation $actorNation;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'app.url' => 'https://nexus.test',
            'milcom.v2_enabled' => true,
            'services.discord_bot_key' => 'milcom-response-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);

        $this->actorNation = Nation::factory()->create();
        $this->actor = $this->linkedUser($this->actorNation, self::ACTOR_DISCORD_ID);
    }

    public function test_actor_can_preview_confirm_and_read_an_allowlisted_current_response(): void
    {
        $assignment = $this->currentAssignment();
        $originalStatus = $assignment->status;

        $preview = $this->preview($assignment, 'acknowledged', null, '456789012345678901')
            ->assertCreated()
            ->assertJsonPath('data.assignment.assignment_id', $assignment->id)
            ->assertJsonPath('data.assignment.response', null)
            ->assertJsonPath('data.proposed_response.response', 'acknowledged')
            ->assertJsonPath('data.proposed_response.reason', null)
            ->assertJsonPath('data.intent.action', DiscordMilcomAssignmentResponseService::INTENT_ACTION)
            ->assertJsonPath('meta.actor_scope', 'actor_current_assignment');

        $intentId = (string) $preview->json('data.intent.id');
        $this->assertSame(64, strlen($intentId));
        $intent = DiscordActionIntent::query()->firstOrFail();
        $this->assertNotSame($intentId, $intent->token_hash);
        $this->assertSame(hash('sha256', $intentId), $intent->token_hash);
        $this->assertSame($this->actor->id, $intent->user_id);
        $this->assertSame(self::GUILD_ID, $intent->guild_id);
        $this->assertNotNull($intent->connection_id);
        $this->assertSame([
            'assignment_id',
            'actor_nation_id',
            'response',
            'reason',
            'resource_version',
        ], array_keys($intent->payload));

        $confirm = $this->confirm($assignment, $intentId, '456789012345678902')
            ->assertCreated()
            ->assertJsonPath('data.assignment_type', 'milcom_v2')
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.response', 'acknowledged')
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('meta.idempotent_replay', false)
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.nation_id')
            ->assertJsonMissingPath('data.discord_interaction_id');

        $this->assertSame($originalStatus, $assignment->fresh()->status);
        $this->assertDatabaseHas('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $assignment->id,
            'user_id' => $this->actor->id,
            'nation_id' => $this->actorNation->id,
            'response' => 'acknowledged',
            'reason' => null,
            'discord_interaction_id' => '456789012345678902',
        ]);

        $list = $this->withHeaders($this->headers('456789012345678903'))
            ->getJson('/api/v1/discord/milcom/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.response.assignment_type', 'milcom_v2')
            ->assertJsonPath('data.0.response.assignment_id', $assignment->id)
            ->assertJsonPath('data.0.response.response', 'acknowledged')
            ->assertJsonMissingPath('data.0.response.user_id')
            ->assertJsonMissingPath('data.0.response.nation_id');
        $this->assertSame($confirm->json('data.responded_at'), $list->json('data.0.response.responded_at'));

        $audit = AuditLog::query()
            ->where('action', 'discord_milcom_v2_assignment_response_recorded')
            ->sole();
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertSame('acknowledged', $audit->context['response']);
        $this->assertArrayNotHasKey('reason', $audit->context);
    }

    public function test_foreign_assignment_is_hidden_from_preview_and_intent_confirmation(): void
    {
        $foreignAssignment = $this->currentAssignment(Nation::factory()->create());

        $this->preview($foreignAssignment, 'acknowledged', null, '456789012345678904')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'milcom_assignment_not_found');

        $assignment = $this->currentAssignment();
        $intentId = (string) $this->preview($assignment, 'acknowledged', null, '456789012345678905')
            ->json('data.intent.id');
        $other = $this->linkedUser(Nation::factory()->create(), self::OTHER_DISCORD_ID);

        $this->withHeaders($this->headers('456789012345678906', self::OTHER_DISCORD_ID))
            ->postJson("/api/v1/discord/milcom/assignments/{$assignment->id}/response/confirm", [
                'intent_id' => $intentId,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->assertDatabaseMissing('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $assignment->id,
            'user_id' => $other->id,
        ]);
    }

    public function test_ended_assignment_is_rejected_and_state_change_after_preview_is_stale(): void
    {
        $ended = $this->currentAssignment();
        $endedWar = $this->createWar(900001, $this->actorNation, $ended->objective->target, [
            'end_date' => now(),
            'turns_left' => 0,
        ]);
        $ended->forceFill(['declared_war_id' => $endedWar->id])->save();

        $this->preview($ended, 'acknowledged', null, '456789012345678907')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'milcom_assignment_not_found');

        $assignment = $this->currentAssignment();
        $intentId = (string) $this->preview($assignment, 'acknowledged', null, '456789012345678908')
            ->json('data.intent.id');
        $assignment->forceFill(['status' => AssignmentStatus::Completed])->save();

        $this->confirm($assignment, $intentId, '456789012345678909')
            ->assertConflict()
            ->assertJsonPath('error.code', 'milcom_assignment_response_stale')
            ->assertJsonPath('error.details.retryable', false);
        $this->assertDatabaseMissing('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $assignment->id,
        ]);
    }

    public function test_unavailable_requires_a_plain_text_reason_and_acknowledged_prohibits_one(): void
    {
        $assignment = $this->currentAssignment();

        $this->preview($assignment, 'unavailable', null, '456789012345678910')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');
        $this->preview($assignment, 'unavailable', '   ', '456789012345678911')
            ->assertUnprocessable();
        $this->preview($assignment, 'acknowledged', 'Not needed', '456789012345678912')
            ->assertUnprocessable();
        $this->preview($assignment, 'declined', null, '456789012345678913')
            ->assertUnprocessable();

        $preview = $this->preview(
            $assignment,
            'unavailable',
            '  I cannot declare before the deadline.  ',
            '456789012345678914',
        )->assertCreated()
            ->assertJsonPath('data.proposed_response.reason', 'I cannot declare before the deadline.');

        $this->confirm($assignment, (string) $preview->json('data.intent.id'), '456789012345678915')
            ->assertCreated()
            ->assertJsonPath('data.response', 'unavailable')
            ->assertJsonPath('data.reason', 'I cannot declare before the deadline.');
    }

    public function test_confirmation_rechecks_actor_verification_removed_after_preview(): void
    {
        $assignment = $this->currentAssignment();
        $intentId = (string) $this->preview($assignment, 'acknowledged', null, '456789012345678916')
            ->json('data.intent.id');
        $this->actor->forceFill(['verified_at' => null])->save();

        $this->confirm($assignment, $intentId, '456789012345678917')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_actor_not_linked');
        $this->assertDatabaseMissing('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $assignment->id,
        ]);
    }

    public function test_unverified_actor_cannot_create_a_preview(): void
    {
        $assignment = $this->currentAssignment();
        $unverifiedNation = Nation::factory()->create();
        $unverified = User::factory()->create([
            'nation_id' => $unverifiedNation->id,
            'verified_at' => null,
        ]);
        DiscordAccount::factory()->create([
            'user_id' => $unverified->id,
            'discord_id' => self::OTHER_DISCORD_ID,
            'unlinked_at' => null,
        ]);

        $this->withHeaders($this->headers('456789012345678918', self::OTHER_DISCORD_ID))
            ->postJson("/api/v1/discord/milcom/assignments/{$assignment->id}/response/preview", [
                'response' => 'acknowledged',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_actor_not_linked');
        $this->assertDatabaseCount('discord_action_intents', 0);
    }

    public function test_duplicate_confirmation_replays_one_sidecar_and_one_audit_entry(): void
    {
        $assignment = $this->currentAssignment();
        $intentId = (string) $this->preview($assignment, 'acknowledged', null, '456789012345678919')
            ->json('data.intent.id');
        $first = $this->confirm($assignment, $intentId, '456789012345678920')
            ->assertCreated()
            ->assertJsonPath('meta.idempotent_replay', false);

        $this->confirm($assignment, $intentId, '456789012345678921')
            ->assertCreated()
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.responded_at', $first->json('data.responded_at'))
            ->assertJsonPath('meta.idempotent_replay', true);

        $this->assertSame(1, DiscordAssignmentResponse::query()
            ->where('assignment_type', 'milcom_v2')
            ->where('assignment_id', $assignment->id)
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'discord_milcom_v2_assignment_response_recorded')
            ->count());
    }

    public function test_same_interaction_replay_with_a_different_intent_is_rejected(): void
    {
        $assignment = $this->currentAssignment();
        $firstIntent = (string) $this->preview($assignment, 'acknowledged', null, '456789012345678922')
            ->json('data.intent.id');
        $secondIntent = (string) $this->preview($assignment, 'unavailable', 'Conflict', '456789012345678923')
            ->json('data.intent.id');
        $interactionId = '456789012345678924';

        $this->confirm($assignment, $firstIntent, $interactionId)->assertCreated();
        $this->confirm($assignment, $secondIntent, $interactionId)
            ->assertConflict()
            ->assertJsonPath('error.code', 'discord_interaction_conflict');

        $this->assertDatabaseHas('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $assignment->id,
            'response' => 'acknowledged',
            'reason' => null,
        ]);
    }

    public function test_legacy_assignment_sidecar_is_never_treated_as_a_milcom_v2_assignment(): void
    {
        $legacyId = 987654;
        DiscordAssignmentResponse::query()->create([
            'assignment_type' => 'plan',
            'assignment_id' => $legacyId,
            'user_id' => $this->actor->id,
            'nation_id' => $this->actorNation->id,
            'response' => 'acknowledged',
            'reason' => null,
        ]);

        $this->withHeaders($this->headers('456789012345678925'))
            ->postJson("/api/v1/discord/milcom/assignments/{$legacyId}/response/preview", [
                'response' => 'unavailable',
                'reason' => 'Should not touch legacy data',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'milcom_assignment_not_found');

        $this->assertDatabaseHas('discord_assignment_responses', [
            'assignment_type' => 'plan',
            'assignment_id' => $legacyId,
            'response' => 'acknowledged',
            'reason' => null,
        ]);
        $this->assertDatabaseMissing('discord_assignment_responses', [
            'assignment_type' => 'milcom_v2',
            'assignment_id' => $legacyId,
        ]);
    }

    private function currentAssignment(?Nation $friendly = null): MilcomAssignment
    {
        $operation = $this->createMilcomOperation(['status' => OperationStatus::Active]);
        $objective = $this->createMilcomObjective($operation, Nation::factory()->create(), [
            'status' => ObjectiveStatus::Dispatched,
            'discord_channel_id' => '567890123456789012',
        ]);

        return $this->createAssignment($objective, $friendly ?? $this->actorNation, [
            'status' => AssignmentStatus::Dispatched,
        ]);
    }

    private function linkedUser(Nation $nation, string $discordId): User
    {
        $user = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => $discordId,
            'unlinked_at' => null,
        ]);

        return $user;
    }

    private function preview(
        MilcomAssignment $assignment,
        string $response,
        ?string $reason,
        string $interactionId,
    ): TestResponse {
        return $this->withHeaders($this->headers($interactionId))
            ->postJson("/api/v1/discord/milcom/assignments/{$assignment->id}/response/preview", array_filter([
                'response' => $response,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null));
    }

    private function confirm(
        MilcomAssignment $assignment,
        string $intentId,
        string $interactionId,
    ): TestResponse {
        return $this->withHeaders($this->headers($interactionId))
            ->postJson("/api/v1/discord/milcom/assignments/{$assignment->id}/response/confirm", [
                'intent_id' => $intentId,
            ]);
    }

    /** @return array<string, string> */
    private function headers(string $interactionId, string $discordId = self::ACTOR_DISCORD_ID): array
    {
        return $this->signedDiscordInteractionHeaders(
            'milcom-response-test-key',
            self::GUILD_ID,
            $discordId,
            $interactionId,
            'milcom.assignment-response',
        );
    }
}
