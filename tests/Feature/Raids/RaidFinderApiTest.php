<?php

namespace Tests\Feature\Raids;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Models\Alliance;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NoRaidList;
use App\Models\RaidAllianceProfile;
use App\Models\RaidTargetClaim;
use App\Models\RaidTargetProfile;
use App\Models\Treaty;
use App\Models\User;
use App\Models\War;
use App\Services\RaidPolicyService;
use App\Services\SettingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRaidFixtures;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class RaidFinderApiTest extends TestCase
{
    use BuildsRaidFixtures;
    use BuildsTestUsers;
    use RefreshDatabase;

    private const MEMBER_ALLIANCE = 777;

    private Nation $attacker;

    private User $user;

    private Alliance $topAlliance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
        Cache::forever('alliances:membership:ids', [self::MEMBER_ALLIANCE]);
        $this->seedRaidMarketPrices(100);
        SettingService::setTopRaidable(1);
        $this->topAlliance = Alliance::factory()->create(['score' => 10_000_000]);
        Alliance::factory()->create(['id' => self::MEMBER_ALLIANCE, 'score' => 1_000]);
        $this->attacker = Nation::factory()->create(['alliance_id' => self::MEMBER_ALLIANCE, 'score' => 1_000, 'war_policy' => 'PIRATE']);
        NationMilitary::query()->create(['nation_id' => $this->attacker->id, 'soldiers' => 150_000, 'tanks' => 5_000]);
        $this->user = User::factory()->verified()->create(['nation_id' => $this->attacker->id]);
    }

    public function test_ineligible_and_protected_targets_are_excluded(): void
    {
        $noRaid = Alliance::factory()->create(['score' => 100]);
        NoRaidList::query()->create(['alliance_id' => $noRaid->id]);
        $treatyPartner = Alliance::factory()->create(['score' => 100]);
        Treaty::query()->create([
            'pw_id' => 1, 'pw_date' => now(), 'turns_left' => 10, 'type' => 'MDP',
            'alliance1_id' => $treatyPartner->id, 'alliance2_id' => $this->topAlliance->id,
        ]);
        $allowedAlliance = Alliance::factory()->create(['score' => 100]);

        $eligible = $this->target();
        $allowedAligned = $this->target(['alliance_id' => $allowedAlliance->id, 'alliance_position' => 'MEMBER']);
        $this->target(['vacation_mode_turns' => 4]);
        $this->target(['defensive_wars' => 3]);
        $beige = $this->target(['beige_turns' => 5]);
        $this->target(['alliance_id' => $this->topAlliance->id, 'alliance_position' => 'MEMBER']);
        $this->target(['alliance_id' => $this->topAlliance->id, 'alliance_position' => 'APPLICANT']);
        $this->target(['alliance_id' => self::MEMBER_ALLIANCE, 'alliance_position' => 'MEMBER']);
        $this->target(['alliance_id' => $noRaid->id, 'alliance_position' => 'MEMBER']);
        $this->target(['alliance_id' => $treatyPartner->id, 'alliance_position' => 'MEMBER']);
        $this->target(['score' => 3_000]);
        $this->target(['score' => 500]);
        $this->target(['computed_at' => null]);
        $this->target(['nation_id' => $this->attacker->id]);
        $fighting = $this->target();
        $foughtBy = $this->target();
        War::query()->create($this->war(9001, $this->attacker->id, $fighting->nation_id));
        War::query()->create($this->war(9002, $foughtBy->nation_id, $this->attacker->id));

        $this->assertEqualsCanonicalizing([$eligible->nation_id, $allowedAligned->nation_id], $this->targetIds($this->finder()));
        $this->assertContains($beige->nation_id, $this->targetIds($this->finder(['beige_within_turns' => 5])));
    }

    public function test_filters_narrow_the_ranked_targets(): void
    {
        $aligned = Alliance::factory()->create(['score' => 100]);
        $rich = $this->target(['baseline_money' => 50_000_000, 'last_active' => now()->subDays(20)]);
        $poor = $this->target(['baseline_money' => 1_100_000, 'last_active' => now()->subHours(3)]);
        $member = $this->target(['alliance_id' => $aligned->id, 'alliance_position' => 'MEMBER', 'baseline_money' => 5_000_000]);
        $applicant = $this->target(['alliance_id' => $aligned->id, 'alliance_position' => 'APPLICANT', 'baseline_money' => 5_000_000]);
        $fortress = $this->target(['soldiers' => 2_000_000, 'tanks' => 100_000, 'baseline_money' => 90_000_000]);

        $this->assertNotContains($poor->nation_id, $this->targetIds($this->finder(['min_expected_net' => 1_000_000])));
        $this->assertContains($rich->nation_id, $this->targetIds($this->finder(['min_expected_net' => 1_000_000])));
        $this->assertNotContains($poor->nation_id, $this->targetIds($this->finder(['min_inactive_days' => 7])));
        $this->assertContains($rich->nation_id, $this->targetIds($this->finder(['min_inactive_days' => 7])));
        $this->assertEqualsCanonicalizing(
            [$rich->nation_id, $poor->nation_id, $applicant->nation_id, $fortress->nation_id],
            $this->targetIds($this->finder(['alliance_scope' => 'unaligned'])),
        );
        $this->assertSame([$member->nation_id], $this->targetIds($this->finder(['alliance_scope' => 'aligned'])));
        $this->assertContains($fortress->nation_id, $this->targetIds($this->finder()));
        $this->assertNotContains($fortress->nation_id, $this->targetIds($this->finder(['beatable_only' => 1])));
    }

    public function test_hide_claimed_drops_targets_claimed_by_other_members(): void
    {
        $claimedByOther = $this->target();
        $claimedByMe = $this->target();
        $other = Nation::factory()->create(['alliance_id' => self::MEMBER_ALLIANCE, 'leader_name' => 'Other Raider']);
        RaidTargetClaim::factory()->create(['target_nation_id' => $claimedByOther->nation_id, 'nation_id' => $other->id]);
        RaidTargetClaim::factory()->create(['target_nation_id' => $claimedByMe->nation_id, 'nation_id' => $this->attacker->id]);

        $rows = collect($this->finder()->json('data'))->keyBy('nation.id');

        $this->assertSame('Other Raider', $rows[$claimedByOther->nation_id]['claim']['leader_name']);
        $this->assertFalse($rows[$claimedByOther->nation_id]['claim']['mine']);
        $this->assertTrue($rows[$claimedByMe->nation_id]['claim']['mine']);
        $this->assertSame([$claimedByMe->nation_id], $this->targetIds($this->finder(['hide_claimed' => 1])));
    }

    public function test_targets_are_ranked_by_expected_net_with_the_documented_shape(): void
    {
        $small = $this->target(['baseline_money' => 3_000_000]);
        $large = $this->target(['baseline_money' => 30_000_000]);

        $response = $this->finder()
            ->assertOk()
            ->assertHeader('X-Nexus-Data-Updated-At')
            ->assertJsonStructure([
                'data' => [[
                    'rank',
                    'nation' => [
                        'id', 'nation_name', 'leader_name', 'alliance', 'alliance_position', 'num_cities', 'score',
                        'last_active', 'activity_bucket', 'beige_turns', 'defensive_wars', 'soldiers', 'tanks',
                        'aircraft', 'ships', 'war_policy',
                    ],
                    'valuation' => [
                        'expected_net', 'expected_net_low', 'expected_net_high', 'gross_loot', 'win_probability',
                        'victory_probability', 'beige_share', 'expected_attacks', 'duration_hours', 'confidence',
                        'components' => [
                            'gross_loot', 'nation_loot', 'ground_loot', 'bank_loot', 'bounty', 'consumables',
                            'military_losses', 'infrastructure_losses', 'counter_risk',
                        ],
                        'loot_resources' => ['money', 'coal', 'food'],
                        'cost_resources' => ['munitions', 'gasoline'],
                        'stockpile' => [
                            'resources', 'value', 'low_value', 'high_value', 'evidence_kind', 'evidence_at',
                            'evidence_age_hours', 'retention', 'activity_bucket',
                        ],
                        'competition' => ['other_attackers'],
                        'counter' => ['probability'],
                        'assumptions',
                    ],
                    'claim',
                ]],
                'meta' => [
                    'generated_at', 'model_version', 'prices_at', 'candidate_count',
                    'attacker' => ['id', 'score', 'range_min', 'range_max', 'offensive_wars', 'offensive_capacity', 'planning_only'],
                ],
            ])
            ->assertJsonPath('meta.model_version', 'raid-valuation-2026-10')
            ->assertJsonPath('meta.attacker.range_min', 750)
            ->assertJsonPath('meta.attacker.range_max', 2500);

        $this->assertSame([$large->nation_id, $small->nation_id], $this->targetIds($response));
        $this->assertSame([1, 2], array_column($response->json('data'), 'rank'));
        $this->assertNull($response->json('data.0.claim'));
    }

    public function test_alliance_profiles_supply_bank_loot_and_counter_rates(): void
    {
        $alliance = Alliance::factory()->create(['score' => 100]);
        RaidAllianceProfile::factory()->withBank(['money' => 50_000_000])->create([
            'alliance_id' => $alliance->id,
            'alliance_score' => 5_000,
            'counter_rate' => 0.35,
        ]);
        $this->target(['alliance_id' => $alliance->id, 'alliance_position' => 'MEMBER']);

        $this->finder()
            ->assertJsonPath('data.0.nation.alliance.id', $alliance->id)
            ->assertJsonPath('data.0.nation.alliance.name', $alliance->name)
            ->assertJsonPath('data.0.valuation.counter.probability', 0.35)
            ->assertJsonPath('data.0.valuation.components.bank_loot', fn (float|int $value): bool => $value > 0);
    }

    public function test_query_count_does_not_grow_with_candidates(): void
    {
        $this->target();
        $this->finder(['fresh' => 1]);

        $this->assertLessThanOrEqual(12, $this->queriesFor(fn () => $this->finder(['fresh' => 1])->assertOk()));

        foreach (range(1, 199) as $index) {
            $this->target();
        }

        $this->assertLessThanOrEqual(12, $this->queriesFor(fn () => $this->finder(['fresh' => 1, 'limit' => 100])->assertOk()->assertJsonCount(100, 'data')));
    }

    public function test_cold_request_stays_within_the_query_budget(): void
    {
        foreach (range(1, 10) as $index) {
            $this->target();
        }

        Cache::forget('raid-policy:protected:1');

        $this->assertLessThanOrEqual(12, $this->queriesFor(fn () => $this->finder()->assertOk()));
    }

    public function test_results_are_cached_until_fresh_is_requested_or_the_policy_changes(): void
    {
        $first = $this->target();
        $this->finder();
        $second = $this->target(['baseline_money' => 99_000_000]);

        $this->assertSame(0, $this->profileQueries(fn () => $this->assertSame([$first->nation_id], $this->targetIds($this->finder()))));
        $this->assertSame(1, $this->profileQueries(fn () => $this->assertContains($second->nation_id, $this->targetIds($this->finder(['fresh' => 1])))));

        $third = $this->target(['baseline_money' => 199_000_000]);
        app(RaidPolicyService::class)->bumpVersion();

        $this->assertSame($third->nation_id, $this->targetIds($this->finder())[0]);
    }

    public function test_impressions_record_the_shown_rank(): void
    {
        $small = $this->target(['baseline_money' => 3_000_000]);
        $large = $this->target(['baseline_money' => 30_000_000]);

        $this->finder();

        $this->assertDatabaseHas('raid_finder_impressions', ['attacker_nation_id' => $this->attacker->id, 'target_nation_id' => $large->nation_id, 'rank' => 1]);
        $this->assertDatabaseHas('raid_finder_impressions', ['attacker_nation_id' => $this->attacker->id, 'target_nation_id' => $small->nation_id, 'rank' => 2]);
    }

    public function test_other_nations_require_the_view_raids_permission(): void
    {
        $other = Nation::factory()->create(['alliance_id' => self::MEMBER_ALLIANCE, 'score' => 1_000]);

        $this->finder(nationId: $other->id)->assertForbidden();

        $this->user = $this->grantPermissions($this->user->fresh(), ['view-raids']);
        $this->finder(nationId: $other->id)->assertOk();
    }

    public function test_non_member_attackers_are_forbidden(): void
    {
        $outsider = Nation::factory()->create(['score' => 1_000]);
        $this->user->update(['nation_id' => $outsider->id]);

        $this->finder(nationId: $outsider->id)->assertForbidden();
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->finder(['limit' => 500, 'alliance_scope' => 'everyone', 'beige_within_turns' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'alliance_scope', 'beige_within_turns']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function finder(array $query = [], ?int $nationId = null): TestResponse
    {
        return $this->actingAs($this->user)
            ->withoutMiddleware([DiscordVerifiedMiddleware::class, EnsureMfaConfigured::class])
            ->getJson(route('api.raid-finder.show', ['nation_id' => $nationId ?? $this->attacker->id, ...$query]));
    }

    /**
     * @return list<int>
     */
    private function targetIds(TestResponse $response): array
    {
        return array_map(fn (array $row): int => $row['nation']['id'], $response->assertOk()->json('data'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function target(array $attributes = []): RaidTargetProfile
    {
        $defaults = [
            'score' => 1_200,
            'soldiers' => 1_000,
            'tanks' => 0,
            'alliance_id' => 0,
            'alliance_position' => null,
            'last_active' => now()->subDays(10),
            'baseline_at' => now()->subDays(2),
            'highest_city_population' => 100_000,
        ];

        foreach ($this->raidResources(['money' => 10_000_000]) as $resource => $amount) {
            $defaults['baseline_'.$resource] = $amount;
            $defaults['daily_net_'.$resource] = 0;
        }

        return RaidTargetProfile::factory()->create([...$defaults, ...$attributes]);
    }

    /**
     * @return array<string, mixed>
     */
    private function war(int $id, int $attackerId, int $defenderId): array
    {
        return [
            'id' => $id, 'date' => now()->subHour(), 'reason' => 'Finder test', 'war_type' => 'RAID', 'turns_left' => 50,
            'att_id' => $attackerId, 'att_alliance_id' => 0, 'att_alliance_position' => 'MEMBER',
            'def_id' => $defenderId, 'def_alliance_id' => 0, 'def_alliance_position' => 'NOALLIANCE',
        ];
    }

    private function queriesFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = collect(DB::getQueryLog())
            ->reject(fn (array $query): bool => $this->isAuthenticationQuery($query['query']))
            ->count();
        DB::disableQueryLog();

        return $queries;
    }

    private function profileQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "raid_target_profiles"'))
            ->count();
        DB::disableQueryLog();

        return $queries;
    }

    private function isAuthenticationQuery(string $sql): bool
    {
        return str_contains($sql, '"users"')
            || str_contains($sql, '"roles"')
            || str_contains($sql, '"role_permissions"')
            || str_contains($sql, '"role_user"');
    }
}
