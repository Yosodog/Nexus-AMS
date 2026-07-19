<?php

namespace Tests\Feature\API;

use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\User;
use App\Services\RaidFinderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class DiscordOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISCORD_ID = '234567890123456789';

    private const GUILD_ID = '123456789012345678';

    private Nation $nation;

    protected function setUp(): void
    {
        parent::setUp();

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

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer operations-test-key',
            'X-Discord-Guild-ID' => self::GUILD_ID,
            'X-Discord-User-ID' => self::DISCORD_ID,
            'X-Discord-Interaction-ID' => '345678901234567890',
        ];
    }
}
