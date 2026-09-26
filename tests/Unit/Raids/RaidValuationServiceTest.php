<?php

namespace Tests\Unit\Raids;

use App\DataTransferObjects\MarketPriceSet;
use App\DataTransferObjects\Raids\RaidAttacker;
use App\DataTransferObjects\Raids\RaidValuation;
use App\DataTransferObjects\Raids\RaidWarContext;
use App\Models\RaidAllianceProfile;
use App\Models\RaidTargetProfile;
use App\Services\Calculators\MilitaryCostCalculator;
use App\Services\Economy\EconomyRules;
use App\Services\Raids\RaidValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaidValuationServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = CarbonImmutable::parse('2026-09-20 12:00:00');
    }

    public function test_dominant_attacker_expects_victory_and_a_positive_return(): void
    {
        $valuation = $this->evaluate($this->attacker(200_000, 10_000), $this->target(['money' => 20_000_000, 'steel' => 5_000]));

        $this->assertGreaterThan(0.99, $valuation->winProbability);
        $this->assertGreaterThan(0.95, $valuation->victoryProbability);
        $this->assertSame(10, $valuation->expectedAttacks);
        $this->assertSame(54.0, $valuation->durationHours);
        $this->assertSame(array_fill(0, 10, 'ground'), $valuation->plan);
        $this->assertGreaterThan(0, $valuation->expectedNet);
        $this->assertGreaterThan(0, $valuation->components['nation_loot']);
        $this->assertGreaterThan(0, $valuation->components['ground_loot']);
        $this->assertSame(0.0, $valuation->components['bounty']);
        $this->assertContains('Bounties are not included.', $valuation->assumptions);
    }

    public function test_weak_attacker_expects_only_costs(): void
    {
        $valuation = $this->evaluate($this->attacker(100, 0), $this->target(['money' => 20_000_000], soldiers: 100_000));

        $this->assertSame(0.0, $valuation->winProbability);
        $this->assertSame(0.0, $valuation->victoryProbability);
        $this->assertSame(0.0, $valuation->grossLoot);
        $this->assertEqualsWithDelta(
            -($valuation->components['consumables'] + $valuation->components['military_losses'] + $valuation->components['counter_risk']),
            $valuation->expectedNet,
            0.02,
        );
        $this->assertLessThanOrEqual(0, $valuation->expectedNet);
    }

    public function test_ground_loot_never_takes_the_protected_first_million(): void
    {
        $valuation = $this->evaluate($this->attacker(500_000, 50_000), $this->target(['money' => 1_500_000]));
        $retainedMoney = 1_500_000 * (1 - 0.03 * 0.5);

        $this->assertEqualsWithDelta($retainedMoney - 1_000_000, $valuation->components['ground_loot'], 0.01);
        $this->assertSame(0.0, $this->evaluate($this->attacker(500_000, 50_000), $this->target(['money' => 900_000]))->components['ground_loot']);
    }

    public function test_bank_loot_requires_an_aligned_member_with_bank_evidence(): void
    {
        $alliance = new RaidAllianceProfile(['alliance_id' => 5, 'alliance_score' => 50_000, 'bank_money' => 10_000_000]);
        $attacker = $this->attacker(200_000, 10_000);

        $member = $this->evaluate($attacker, $this->target(['money' => 2_000_000], allianceId: 5, position: 'MEMBER'), $alliance);
        $applicant = $this->evaluate($attacker, $this->target(['money' => 2_000_000], allianceId: 5, position: 'APPLICANT'), $alliance);
        $unaligned = $this->evaluate($attacker, $this->target(['money' => 2_000_000]), $alliance);
        $noEvidence = $this->evaluate($attacker, $this->target(['money' => 2_000_000], allianceId: 5, position: 'MEMBER'), new RaidAllianceProfile(['alliance_id' => 5, 'alliance_score' => 50_000]));

        $this->assertGreaterThan(0, $member->components['bank_loot']);
        $this->assertSame(0.0, $applicant->components['bank_loot']);
        $this->assertSame(0.0, $unaligned->components['bank_loot']);
        $this->assertSame(0.0, $noEvidence->components['bank_loot']);
        $this->assertContains('Alliance bank loot is unavailable.', $noEvidence->assumptions);
        $this->assertNotContains('Alliance bank loot is unavailable.', $unaligned->assumptions);
    }

    public function test_one_competing_attacker_halves_victory_loot(): void
    {
        $attacker = $this->attacker(200_000, 10_000);
        $target = $this->target(['money' => 20_000_000, 'food' => 10_000]);

        $alone = $this->evaluate($attacker, $target);
        $shared = $this->evaluate($attacker, $target, context: new RaidWarContext(1, 0.1));

        $this->assertSame(0.5, $shared->beigeShare);
        $this->assertEqualsWithDelta($alone->components['nation_loot'] / 2, $shared->components['nation_loot'], 0.02);
        $this->assertSame($alone->components['ground_loot'], $shared->components['ground_loot']);
    }

    public function test_range_brackets_the_expectation_and_components_reconcile(): void
    {
        $valuation = $this->evaluate($this->attacker(150_000, 5_000), $this->target(['money' => 8_000_000, 'steel' => 2_000], soldiers: 20_000));

        $this->assertLessThanOrEqual($valuation->expectedNet, $valuation->expectedNetLow);
        $this->assertGreaterThanOrEqual($valuation->expectedNet, $valuation->expectedNetHigh);
        $this->assertEqualsWithDelta(
            $valuation->components['gross_loot'] - $valuation->components['consumables'] - $valuation->components['military_losses'] - $valuation->components['counter_risk'],
            $valuation->expectedNet,
            0.02,
        );
        $this->assertEqualsWithDelta(
            $valuation->components['ground_loot'] + $valuation->components['nation_loot'] + $valuation->components['bank_loot'],
            $valuation->grossLoot,
            0.02,
        );
        $this->assertSame('high', $valuation->confidence);
        $this->assertSame(['munitions', 'gasoline'], array_keys($valuation->costResources));
    }

    public function test_unarmed_soldiers_do_not_consume_munitions(): void
    {
        $attacker = $this->attacker(100_000, 0, resources: array_fill_keys(EconomyRules::RESOURCE_KEYS, 0.0));

        $valuation = $this->evaluate($attacker, $this->target(['money' => 5_000_000]));

        $this->assertSame(0.0, $valuation->costResources['munitions']);
    }

    private function evaluate(
        RaidAttacker $attacker,
        RaidTargetProfile $target,
        ?RaidAllianceProfile $alliance = null,
        ?RaidWarContext $context = null,
    ): RaidValuation {
        return app(RaidValuationService::class)->evaluate(
            $attacker,
            $target,
            $alliance,
            $context ?? new RaidWarContext(0, 0.1),
            MarketPriceSet::symmetric(array_fill_keys(EconomyRules::TRADE_RESOURCES, 100)),
            $this->at,
        );
    }

    /**
     * @param  array<string, float>|null  $resources
     */
    private function attacker(int $soldiers, int $tanks, ?array $resources = null): RaidAttacker
    {
        return new RaidAttacker(
            nationId: 1,
            score: 2_000,
            allianceId: 777,
            warPolicy: 'PIRATE',
            domesticPolicy: 'MANIFEST_DESTINY',
            soldiers: $soldiers,
            tanks: $tanks,
            aircraft: 0,
            ships: 0,
            resources: $resources,
            privateResources: $resources === null,
            pirateEconomy: false,
            advancedPirateEconomy: false,
            governmentSupportAgency: false,
            bureauOfDomesticAffairs: false,
            militaryResearch: array_fill_keys(MilitaryCostCalculator::RESEARCH_FIELDS, 0),
            offensiveWars: 0,
            offensiveCapacity: 5,
        );
    }

    /**
     * @param  array<string, float|int>  $stockpile
     */
    private function target(
        array $stockpile,
        int $soldiers = 1_000,
        int $allianceId = 0,
        ?string $position = null,
    ): RaidTargetProfile {
        $attributes = [
            'nation_id' => 2,
            'alliance_id' => $allianceId,
            'alliance_position' => $position,
            'soldiers' => $soldiers,
            'tanks' => 0,
            'highest_city_population' => 100_000,
            'war_policy' => 'TURTLE',
            'last_active' => null,
            'baseline_kind' => RaidTargetProfile::BASELINE_LOOT,
            'baseline_at' => $this->at->subDay(),
            'retention_samples' => 0,
        ];

        foreach (EconomyRules::RESOURCE_KEYS as $resource) {
            $attributes['baseline_'.$resource] = $stockpile[$resource] ?? 0;
            $attributes['daily_net_'.$resource] = 0;
        }

        return new RaidTargetProfile($attributes);
    }
}
