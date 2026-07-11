<?php

namespace Tests\Feature\API;

use App\Models\AlertSubscription;
use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DiscordAlertSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private const GUILD_ID = '123456789012345678';

    private const DISCORD_ID = '234567890123456789';

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $alliance = Alliance::factory()->create();
        config([
            'services.discord_bot_key' => 'alerts-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.pw.alliance_id' => $alliance->id,
        ]);
        Cache::flush();
        app(AllianceMembershipService::class)->refresh();

        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'alliance_position' => 'MEMBER',
        ]);
        $this->actor = User::factory()->verified()->create(['nation_id' => $nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_member_can_create_list_pause_and_delete_own_alert(): void
    {
        $created = $this->withHeaders($this->headers('345678901234567890'))
            ->postJson('/api/v1/discord/me/alerts', [
                'type' => 'nation',
                'target_id' => $this->actor->nation_id,
                'events' => ['beige_exited'],
                'cooldown_minutes' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.active', true);

        $alertId = $created->json('data.id');

        $this->withHeaders($this->headers('456789012345678901'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alertId);

        $this->withHeaders($this->headers('567890123456789012'))
            ->patchJson('/api/v1/discord/me/alerts/'.$alertId.'/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->withHeaders($this->headers('678901234567890123'))
            ->deleteJson('/api/v1/discord/me/alerts/'.$alertId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('alert_subscriptions', ['id' => $alertId]);
    }

    public function test_applicant_and_non_owner_cannot_manage_alerts(): void
    {
        $otherUser = User::factory()->verified()->create([
            'nation_id' => Nation::factory()->create([
                'alliance_id' => $this->actor->nation->alliance_id,
                'alliance_position' => 'MEMBER',
            ])->id,
        ]);
        $otherAlert = AlertSubscription::query()->create([
            'user_id' => $otherUser->id,
            'type' => 'market',
            'config' => ['resource' => 'steel', 'direction' => 'above', 'threshold' => 4000],
            'is_active' => true,
            'cooldown_minutes' => 60,
        ]);

        $this->withHeaders($this->headers('789012345678901234'))
            ->patchJson('/api/v1/discord/me/alerts/'.$otherAlert->id.'/status', ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->actor->nation()->update(['alliance_position' => 'APPLICANT']);

        $this->withHeaders($this->headers('890123456789012345'))
            ->getJson('/api/v1/discord/me/alerts')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    /** @return array<string, string> */
    private function headers(string $interactionId): array
    {
        return [
            'Authorization' => 'Bearer alerts-test-key',
            'X-Discord-Guild-ID' => self::GUILD_ID,
            'X-Discord-User-ID' => self::DISCORD_ID,
            'X-Discord-Interaction-ID' => $interactionId,
        ];
    }
}
