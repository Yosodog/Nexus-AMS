<?php

namespace Tests\Feature\API;

use App\Enums\SpyAssignmentStatus;
use App\Enums\SpyCampaignStatus;
use App\Enums\SpyOperationType;
use App\Enums\SpyRoundStatus;
use App\Http\Middleware\RejectLegacyMilcomMutations;
use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\SpyAssignment;
use App\Models\SpyCampaign;
use App\Models\SpyRound;
use App\Models\User;
use App\Models\War;
use App\Models\WarCounter;
use App\Models\WarCounterAssignment;
use App\Services\RaidFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\Concerns\SignsDiscordInteractions;
use Tests\TestCase;

class DiscordOperationsApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsDiscordInteractions;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private Nation $nation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDiscordInteractionSigning();

        config([
            'services.discord_bot_key' => 'operations-test-key',
            'services.discord.guild_id' => self::GUILD_ID,
        ]);
        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);

        $this->nation = Nation::factory()->create(['alliance_id' => 777]);
        $actor = User::factory()->verified()->create(['nation_id' => $this->nation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $actor->id,
            'discord_id' => self::DISCORD_ID,
            'unlinked_at' => null,
        ]);
    }

    public function test_war_counter_requires_a_nation_id(): void
    {
        $this->withoutMiddleware(RejectLegacyMilcomMutations::class);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/wars/counter')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.details.nation_id.0', 'The nation id field is required.')
            ->assertJsonPath('meta.contract_version', 1);
    }

    public function test_accessible_open_war_counter_returns_contract_one_and_documented_fields(): void
    {
        $this->withoutMiddleware(RejectLegacyMilcomMutations::class);

        $aggressor = Nation::factory()->create();
        $counter = WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'active',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
        ]);
        WarCounterAssignment::query()->create([
            'war_counter_id' => $counter->id,
            'friendly_nation_id' => $this->nation->id,
            'status' => 'assigned',
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/wars/counter?nation_id='.$aggressor->id)
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.items.0.id', $counter->id)
            ->assertJsonPath('data.items.0.status', 'active')
            ->assertJsonPath('data.items.0.type', 'ordinary')
            ->assertJsonPath('data.items.0.target.id', $aggressor->id)
            ->assertJsonPath('data.items.0.target.nation_name', $aggressor->nation_name)
            ->assertJsonPath('data.items.0.team_size', 3)
            ->assertJsonPath('data.items.0.assigned_nation_ids.0', $this->nation->id)
            ->assertJsonPath(
                'data.items.0.deep_link_path',
                route('admin.war-counters.show', ['counter' => $counter], absolute: false),
            );

        $this->assertEqualsCanonicalizing([
            'id',
            'status',
            'type',
            'target',
            'team_size',
            'assigned_nation_ids',
            'deep_link_path',
        ], array_keys($response->json('data.items.0')));
    }

    public function test_war_counter_returns_counter_not_found_when_actor_cannot_access_open_counter(): void
    {
        $this->withoutMiddleware(RejectLegacyMilcomMutations::class);

        $aggressor = Nation::factory()->create();
        WarCounter::query()->create([
            'aggressor_nation_id' => $aggressor->id,
            'status' => 'active',
            'team_size' => 3,
            'war_declaration_type' => 'ordinary',
        ]);

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/wars/counter?nation_id='.$aggressor->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'counter_not_found')
            ->assertJsonPath('meta.contract_version', 1);
    }

    public function test_war_simulation_returns_participant_payload(): void
    {
        $defender = Nation::factory()->create();
        $war = War::query()->create([
            'date' => now()->subHours(2),
            'reason' => 'Test participant simulation',
            'war_type' => 'ORDINARY',
            'att_id' => $this->nation->id,
            'def_id' => $defender->id,
            'att_alliance_id' => $this->nation->alliance_id,
            'def_alliance_id' => $defender->alliance_id,
            'turns_left' => 10,
        ]);

        $response = $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/wars/'.$war->id.'/simulation')
            ->assertOk()
            ->assertJsonPath('meta.contract_version', 1)
            ->assertJsonPath('data.war_id', $war->id)
            ->assertJsonPath(
                'data.deep_link_path',
                route('defense.simulators', ['war' => $war->id], absolute: false),
            )
            ->assertJsonStructure([
                'data' => [
                    'war_id',
                    'summary',
                    'context',
                    'deep_link_path',
                ],
                'meta' => ['contract_version'],
            ]);

        $this->assertEqualsCanonicalizing([
            'war_id',
            'summary',
            'context',
            'deep_link_path',
        ], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing([
            'war_type',
            'attacker_policy',
            'defender_policy',
            'air_superiority_owner',
            'ground_control_owner',
            'blockade_owner',
            'blitz_active_attacker',
            'blitz_active_defender',
        ], array_keys($response->json('data.context')));
        $this->assertNotSame('', $response->json('data.summary'));
    }

    public function test_war_simulation_forbids_an_unrelated_non_privileged_actor(): void
    {
        $defender = Nation::factory()->create();
        $war = War::query()->create([
            'reason' => 'Test authorization boundary',
            'war_type' => 'ORDINARY',
            'att_id' => $this->nation->id,
            'def_id' => $defender->id,
            'att_alliance_id' => $this->nation->alliance_id,
            'def_alliance_id' => $defender->alliance_id,
            'turns_left' => 10,
        ]);
        $unrelatedNation = Nation::factory()->create(['alliance_id' => 777]);
        $unrelatedUser = User::factory()->verified()->create(['nation_id' => $unrelatedNation->id]);
        DiscordAccount::factory()->create([
            'user_id' => $unrelatedUser->id,
            'discord_id' => '345678901234567891',
            'unlinked_at' => null,
        ]);

        $this->withHeaders($this->headers('345678901234567891'))
            ->getJson('/api/v1/discord/me/wars/'.$war->id.'/simulation')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden')
            ->assertJsonPath('meta.contract_version', 1);
    }

    public function test_raid_targets_include_identity_links_loot_and_military_context(): void
    {
        $target = collect([
            'nation' => (object) [
                'id' => 9876,
                'nation_name' => 'Raid Target',
                'leader_name' => 'Target Leader',
                'alliance_id' => 456,
                'alliance' => (object) ['name' => 'Target Alliance'],
                'num_cities' => 31,
                'score' => 7654.32,
                'last_active' => '2026-07-19T12:00:00Z',
                'soldiers' => 120000,
                'tanks' => 8000,
                'aircraft' => 2100,
                'ships' => 75,
                'spies' => 55,
                'missiles' => 4,
                'nukes' => 2,
            ],
            'value' => 42157764,
            'last_beige' => 38750000,
            'defensive_wars' => 1,
        ]);

        $this->mock(RaidFinderService::class, function (MockInterface $mock) use ($target): void {
            $mock->shouldReceive('findTargets')
                ->once()
                ->with($this->nation->id)
                ->andReturn(collect([$target]));
        });

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/raids?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.nation_name', 'Raid Target')
            ->assertJsonPath('data.0.leader_name', 'Target Leader')
            ->assertJsonPath('data.0.alliance_id', 456)
            ->assertJsonPath('data.0.alliance_name', 'Target Alliance')
            ->assertJsonPath('data.0.estimated_value', 42157764)
            ->assertJsonPath('data.0.last_beige_value', 38750000)
            ->assertJsonPath('data.0.military.soldiers', 120000)
            ->assertJsonPath('data.0.military.aircraft', 2100)
            ->assertJsonPath('data.0.nation_url', 'https://politicsandwar.com/nation/id=9876')
            ->assertJsonPath('data.0.alliance_url', 'https://politicsandwar.com/alliance/id=456');
    }

    public function test_spy_assignments_only_include_sent_orders_from_active_assigned_rounds(): void
    {
        $activeCampaign = SpyCampaign::query()->create([
            'name' => 'Published campaign',
            'status' => SpyCampaignStatus::ACTIVE,
        ]);
        $assignedRound = SpyRound::query()->create([
            'spy_campaign_id' => $activeCampaign->id,
            'round_number' => 1,
            'op_type' => SpyOperationType::GATHER_INTELLIGENCE,
            'status' => SpyRoundStatus::ASSIGNED,
        ]);

        $visibleAssignment = $this->createSpyAssignment(
            $assignedRound,
            SpyAssignmentStatus::SENT,
            Nation::factory()->create()
        );
        $this->createSpyAssignment(
            $assignedRound,
            SpyAssignmentStatus::PENDING,
            Nation::factory()->create()
        );

        $draftRound = SpyRound::query()->create([
            'spy_campaign_id' => $activeCampaign->id,
            'round_number' => 2,
            'op_type' => SpyOperationType::GATHER_INTELLIGENCE,
            'status' => SpyRoundStatus::DRAFT,
        ]);
        $this->createSpyAssignment($draftRound, SpyAssignmentStatus::SENT, Nation::factory()->create());

        $draftCampaign = SpyCampaign::query()->create([
            'name' => 'Draft campaign',
            'status' => SpyCampaignStatus::DRAFT,
        ]);
        $draftCampaignRound = SpyRound::query()->create([
            'spy_campaign_id' => $draftCampaign->id,
            'round_number' => 1,
            'op_type' => SpyOperationType::GATHER_INTELLIGENCE,
            'status' => SpyRoundStatus::ASSIGNED,
        ]);
        $this->createSpyAssignment($draftCampaignRound, SpyAssignmentStatus::SENT, Nation::factory()->create());

        $this->withHeaders($this->headers())
            ->getJson('/api/v1/discord/me/spy-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleAssignment->id)
            ->assertJsonPath('data.0.status', SpyAssignmentStatus::SENT->value)
            ->assertJsonPath('data.0.campaign.name', 'Published campaign');
    }

    private function createSpyAssignment(
        SpyRound $round,
        SpyAssignmentStatus $status,
        Nation $defender
    ): SpyAssignment {
        return SpyAssignment::query()->create([
            'spy_round_id' => $round->id,
            'attacker_nation_id' => $this->nation->id,
            'defender_nation_id' => $defender->id,
            'op_type' => SpyOperationType::GATHER_INTELLIGENCE,
            'status' => $status,
        ]);
    }

    /** @return array<string, string> */
    private function headers(string $discordId = self::DISCORD_ID): array
    {
        return $this->signedDiscordInteractionHeaders(
            'operations-test-key',
            self::GUILD_ID,
            $discordId,
            '345678901234567890',
            'war',
        );
    }
}
