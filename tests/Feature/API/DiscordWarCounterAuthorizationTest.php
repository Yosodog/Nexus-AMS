<?php

namespace Tests\Feature\API;

use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Models\WarCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordWarCounterAuthorizationTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();
        config()->set('milcom.v1_enabled', true);
        config()->set('milcom.v2_requested', false);
        config()->set('milcom.v2_enabled', false);

        config([
            'services.discord_bot_key' => 'discord-war-counter-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
    }

    public function test_actor_interaction_cannot_impersonate_the_service_callback(): void
    {
        $counter = $this->counter();
        $this->linkActor(isAdmin: false);

        $this->withHeaders($this->headers('345678901234567890'))
            ->postJson('/api/v1/discord/war-counters/attach-channel', [
                'war_counter_id' => $counter->id,
                'discord_channel_id' => '456789012345678901',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_interaction_action_mismatch');

        $this->assertNull($counter->fresh()->discord_channel_id);
    }

    public function test_signed_service_can_attach_and_authenticated_admin_can_archive_a_counter(): void
    {
        $counter = $this->counter();
        $this->linkActor(isAdmin: true);

        $this->withHeaders($this->signedDiscordServiceHeaders(
            'discord-war-counter-test-key',
            self::GUILD_ID,
            'war-counters.attach-channel',
        ))
            ->postJson('/api/v1/discord/war-counters/attach-channel', [
                'war_counter_id' => $counter->id,
                'discord_channel_id' => '567890123456789012',
            ])
            ->assertOk()
            ->assertJsonPath('counter.discord_channel_id', '567890123456789012');

        $this->withHeaders($this->headers('567890123456789012', 'archivecounter'))
            ->postJson('/api/v1/discord/war-counters/archive', [
                'war_counter_id' => $counter->id,
            ])
            ->assertOk()
            ->assertJsonPath('archived', true);

        $this->assertSame('archived', $counter->fresh()->status);
    }

    public function test_signed_service_proof_cannot_be_replayed_with_a_different_request_body(): void
    {
        $firstCounter = $this->counter();
        $secondCounter = $this->counter();
        $headers = $this->signedDiscordServiceHeaders(
            'discord-war-counter-test-key',
            self::GUILD_ID,
            'war-counters.attach-channel',
        );
        $firstRequest = [
            'war_counter_id' => $firstCounter->id,
            'discord_channel_id' => '567890123456789012',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/war-counters/attach-channel', $firstRequest)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/war-counters/attach-channel', $firstRequest)
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/discord/war-counters/attach-channel', [
                'war_counter_id' => $secondCounter->id,
                'discord_channel_id' => '678901234567890123',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'replayed_discord_service_proof');

        $this->assertSame('567890123456789012', $firstCounter->fresh()->discord_channel_id);
        $this->assertNull($secondCounter->fresh()->discord_channel_id);
    }

    private function counter(): WarCounter
    {
        $aggressor = Nation::factory()->create();

        return WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'active',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
        ]);
    }

    private function linkActor(bool $isAdmin): User
    {
        $nation = Nation::factory()->create();
        $user = $this->grantPermissions(
            User::factory()->verified()->create([
                'nation_id' => $nation->id,
                'is_admin' => $isAdmin,
            ]),
            ['manage-war-room'],
        );
        DiscordAccount::factory()->create([
            'user_id' => $user->id,
            'discord_id' => self::DISCORD_ID,
        ]);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(string $interactionId, string $command = 'war.counter'): array
    {
        return $this->signedDiscordInteractionHeaders(
            'discord-war-counter-test-key',
            self::GUILD_ID,
            self::DISCORD_ID,
            $interactionId,
            $command,
        );
    }
}
