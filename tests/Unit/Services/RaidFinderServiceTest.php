<?php

namespace Tests\Unit\Services;

use App\Models\Nation;
use App\Models\TradePrice;
use App\Services\AllianceMembershipService;
use App\Services\GraphQLQueryBuilder;
use App\Services\LootCalculatorService;
use App\Services\QueryService;
use App\Services\RaidFinderService;
use App\Services\RaidPolicyService;
use App\Services\SettingService;
use App\Services\TradePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RaidFinderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_generation_applies_the_configured_activity_policy(): void
    {
        $this->travelTo('2026-08-12 12:00:00 UTC');
        SettingService::setRaidActivityCityThreshold(10);
        SettingService::setRaidMinimumInactiveTurns(12);

        $ownNation = Nation::factory()->create([
            'alliance_id' => 777,
            'score' => 1000,
        ]);

        $queryService = Mockery::mock(QueryService::class);
        $queryService->shouldReceive('sendQuery')
            ->once()
            ->withArgs(fn (GraphQLQueryBuilder $query): bool => str_contains($query->build(), 'last_active'))
            ->andReturn((object) [
                $this->targetPayload(1001, '2026-08-12 11:00:00'),
                $this->targetPayload(1002, '2026-08-11 12:00:00'),
            ]);
        $this->app->instance(QueryService::class, $queryService);

        $prices = Mockery::mock(TradePriceService::class);
        $prices->shouldReceive('get24hAverage')->once()->andReturn(new TradePrice);
        $this->app->instance(TradePriceService::class, $prices);

        $loot = Mockery::mock(LootCalculatorService::class);
        $loot->shouldReceive('calculateFromGraphQLWar')->once()->andReturn(1_000_000);
        $this->app->instance(LootCalculatorService::class, $loot);

        $membership = Mockery::mock(AllianceMembershipService::class);
        $membership->shouldReceive('contains')->once()->with(777)->andReturnTrue();
        $this->app->instance(AllianceMembershipService::class, $membership);

        $policy = Mockery::mock(RaidPolicyService::class);
        $policy->shouldReceive('raidableAllianceIds')->once()->andReturn([123]);
        $this->app->instance(RaidPolicyService::class, $policy);

        $targets = app(RaidFinderService::class)->findTargets($ownNation->id);

        $this->assertSame([1002], $targets->pluck('nation.id')->all());
        $this->assertSame([1_000_000], $targets->pluck('value')->all());
    }

    /** @return array<string, mixed> */
    private function targetPayload(int $id, string $lastActive): array
    {
        return [
            'id' => $id,
            'leader_name' => 'Target '.$id,
            'alliance_id' => 123,
            'last_active' => $lastActive,
            'score' => 1000,
            'num_cities' => 11,
            'alliance' => [
                'id' => 123,
                'name' => 'Raidable Alliance',
                'acronym' => 'RAID',
                'score' => 1000,
                'color' => 'gray',
                'accept_members' => false,
                'rank' => 100,
            ],
            'wars' => [[
                'id' => $id + 5000,
                'date' => '2026-08-01 00:00:00',
                'def_id' => $id,
                'winner_id' => 999999,
                'turns_left' => 0,
                'attacks' => [],
            ]],
        ];
    }
}
