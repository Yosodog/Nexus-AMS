<?php

namespace Tests\Feature\API;

use App\Models\Alliance;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\User;
use App\Services\Economy\EconomyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTestUsers;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordDirectoryApiTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const ACTOR_DISCORD_ID = '123456789012345678';

    private const TARGET_DISCORD_ID = '223456789012345678';

    private const GUILD_ID = '323456789012345678';

    private User $actor;

    private Nation $actorNation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();
        config([
            'services.discord_bot_key' => 'discord-directory-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
            'services.discord.summary_stale_after_seconds' => 1800,
        ]);
        $alliance = Alliance::factory()->create(['name' => 'Nexus Alliance', 'acronym' => 'NEX']);
        $this->actorNation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'nation_name' => 'Actor Nation',
            'leader_name' => 'Actor Leader',
        ]);
        $this->actor = User::factory()->verified()->create(['nation_id' => $this->actorNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $this->actor->id,
            'discord_id' => self::ACTOR_DISCORD_ID,
            'discord_username' => 'actor.user',
            'unlinked_at' => null,
        ]);
    }

    public function test_member_can_view_only_their_minimal_linked_identity(): void
    {
        $response = $this->withHeaders($this->headers('who'))
            ->getJson('/api/v1/discord/directory/discord-users/'.self::ACTOR_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.nation.id', $this->actorNation->id)
            ->assertJsonPath('data.alliance.name', 'Nexus Alliance')
            ->assertJsonPath('data.deep_link_path', '/user/dashboard')
            ->assertJsonPath('meta.guild_id', self::GUILD_ID);

        $encoded = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('email', $encoded);
        $this->assertStringNotContainsString('roles', $encoded);
        $this->assertStringNotContainsString('balance', $encoded);
    }

    public function test_another_user_requires_nexus_member_view_permission(): void
    {
        $targetNation = Nation::factory()->create();
        $target = User::factory()->verified()->create(['nation_id' => $targetNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $target->id,
            'discord_id' => self::TARGET_DISCORD_ID,
            'unlinked_at' => null,
        ]);

        $this->withHeaders($this->headers('who'))
            ->getJson('/api/v1/discord/directory/discord-users/'.self::TARGET_DISCORD_ID)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->actor->forceFill(['is_admin' => true])->save();
        $this->actor = $this->grantPermissions($this->actor->fresh(), ['view-members']);
        $this->withHeaders($this->headers('who'))
            ->getJson('/api/v1/discord/directory/discord-users/'.self::TARGET_DISCORD_ID)
            ->assertOk()
            ->assertJsonPath('data.nation.id', $targetNation->id)
            ->assertJsonPath('data.deep_link_path', '/admin/members/'.$targetNation->id);
    }

    public function test_nation_and_alliance_projections_are_allowlisted_shareable_and_freshness_aware(): void
    {
        $alliance = Alliance::factory()->create([
            'name' => 'Directory Alliance',
            'acronym' => 'DIR',
            'rank' => 12,
        ]);
        $nation = Nation::factory()->create([
            'alliance_id' => $alliance->id,
            'nation_name' => 'Directory Nation',
            'leader_name' => 'Directory Leader',
            'num_cities' => 25,
        ]);
        $nation->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $alliance->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $this->withHeaders($this->headers('nation'))
            ->getJson('/api/v1/discord/directory/nations?query=Directory')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $nation->id);
        $nationResponse = $this->withHeaders($this->headers('nation'))
            ->getJson('/api/v1/discord/directory/nations/'.$nation->id)
            ->assertOk()
            ->assertJsonPath('data.shareable', true)
            ->assertJsonPath('data.freshness.state', 'stale')
            ->assertJsonPath('data.alliance.id', $alliance->id);

        $this->withHeaders($this->headers('alliance'))
            ->getJson('/api/v1/discord/directory/alliances?query=DIR')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $alliance->id);
        $allianceResponse = $this->withHeaders($this->headers('alliance'))
            ->getJson('/api/v1/discord/directory/alliances/'.$alliance->id)
            ->assertOk()
            ->assertJsonPath('data.shareable', true)
            ->assertJsonPath('data.freshness.state', 'stale')
            ->assertJsonPath('data.nation_count', 1);

        foreach ([$nationResponse->json('data'), $allianceResponse->json('data')] as $payload) {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('resources', $encoded);
            $this->assertStringNotContainsString('military', $encoded);
            $this->assertStringNotContainsString('bank', $encoded);
        }
    }

    public function test_member_can_view_their_current_recommended_build(): void
    {
        NationBuildRecommendation::query()->create([
            'nation_id' => $this->actorNation->id,
            'alliance_id' => $this->actorNation->alliance_id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'recommended_build_json' => [
                'infra_needed' => 2500,
                'imp_total' => 50,
                'imp_nuclearpower' => 1,
                'imp_farm' => 10,
                'imp_munitionsfactory' => 5,
                'imp_hospital' => 1,
                'imp_hangars' => 5,
            ],
            'infra_needed' => 2500,
            'land_used' => 2500,
            'imp_total' => 50,
            'available_slots' => 50,
            'cities_below_target' => 2,
            'infrastructure_shortfall' => 125.5,
            'converted_profit_per_day' => 1234567.89,
            'money_profit_per_day' => 456789.12,
            'resource_profit_per_day' => ['food' => 100],
            'disease' => 1.25,
            'pollution' => 10,
            'crime' => 0.5,
            'commerce' => 115,
            'population' => 125000,
            'price_basis' => 'test prices',
            'calculation_context' => ['market' => ['stale' => true]],
            'calculated_at' => now(),
        ]);

        $response = $this->withHeaders($this->headers('build'))
            ->getJson('/api/v1/discord/me/build-recommendation')
            ->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.nation.id', $this->actorNation->id)
            ->assertJsonPath('data.recommendation.target_infrastructure', 2500)
            ->assertJsonPath('data.recommendation.groups.0.key', 'power')
            ->assertJsonPath('data.recommendation.groups.0.items.0.label', 'Nuclear Power Plant')
            ->assertJsonPath('data.recommendation.market_stale', true)
            ->assertJsonPath('data.deep_link_path', '/audit')
            ->assertJsonPath('meta.actor_scope', 'self');

        $encoded = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('resource_profit_per_day', $encoded);
    }

    public function test_member_receives_an_unavailable_state_without_a_current_recommendation(): void
    {
        $this->withHeaders($this->headers('build'))
            ->getJson('/api/v1/discord/me/build-recommendation')
            ->assertOk()
            ->assertJsonPath('data.state', 'unavailable')
            ->assertJsonPath('data.nation.id', $this->actorNation->id)
            ->assertJsonMissingPath('data.recommendation');
    }

    public function test_build_recommendation_requires_the_build_command_signature(): void
    {
        $this->withHeaders($this->headers('nation'))
            ->getJson('/api/v1/discord/me/build-recommendation')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'discord_interaction_action_mismatch');
    }

    private function headers(string $command): array
    {
        return $this->signedDiscordInteractionHeaders(
            'discord-directory-test-key',
            self::GUILD_ID,
            self::ACTOR_DISCORD_ID,
            (string) random_int(100000000000000000, 999999999999999999),
            $command,
        );
    }
}
