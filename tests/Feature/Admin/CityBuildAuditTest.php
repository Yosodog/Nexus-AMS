<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\City;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use App\Models\User;
use App\Services\Economy\EconomyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTestUsers;
use Tests\TestCase;

class CityBuildAuditTest extends TestCase
{
    use BuildsTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Queue::fake();

        $this->withoutMiddleware([
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ]);
    }

    public function test_viewer_can_review_member_city_builds_and_copy_recommendations(): void
    {
        $viewer = $this->createAdmin(['view-audits']);
        $nation = $this->createMemberNation();
        $this->createCity($nation, [
            'infrastructure' => 1900,
            'land' => 1400,
            'powered' => false,
        ]);
        $nation->update(['num_cities' => 2]);
        $this->createCity($nation, [
            'name' => 'Hidden Different City',
            'nuclear_power' => 1,
        ]);
        $this->createRecommendation($nation);

        $this->actingAs($viewer)
            ->get(route('admin.audits.city-builds.index'))
            ->assertOk()
            ->assertSee('Member City Build Audit')
            ->assertSee($nation->nation_name)
            ->assertSee('Needs changes')
            ->assertSee('1 city differs from first')
            ->assertSee('Paradise City')
            ->assertDontSee('Hidden Different City')
            ->assertSee('Copy message')
            ->assertSee('Copy JSON')
            ->assertDontSee('Refresh all builds')
            ->assertDontSee('Regenerate');
    }

    public function test_admin_without_audit_permission_cannot_view_city_build_audit(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.audits.city-builds.index'))
            ->assertForbidden();
    }

    public function test_manager_can_queue_a_member_build_recommendation(): void
    {
        $manager = $this->createAdmin(['view-audits', 'manage-audits']);
        $nation = $this->createMemberNation();
        $marketSnapshot = $this->createCalculationInputs();

        $this->actingAs($manager)
            ->post(route('admin.audits.city-builds.recommendations.regenerate', $nation))
            ->assertRedirect()
            ->assertSessionHas('alert-type', 'success');

        Queue::assertPushed(
            RefreshNationBuildRecommendationJob::class,
            fn (RefreshNationBuildRecommendationJob $job): bool => $job->nationId === $nation->id
                && $job->marketPriceSnapshotId === $marketSnapshot->id
                && $job->radiationSnapshotId !== null,
        );
    }

    public function test_viewer_cannot_queue_build_recommendations(): void
    {
        $viewer = $this->createAdmin(['view-audits']);
        $nation = $this->createMemberNation();

        $this->actingAs($viewer)
            ->post(route('admin.audits.city-builds.recommendations.regenerate', $nation))
            ->assertForbidden();
    }

    public function test_manager_can_queue_build_recommendations_for_all_eligible_members(): void
    {
        $manager = $this->createAdmin(['view-audits', 'manage-audits']);
        $firstNation = $this->createMemberNation();
        $secondNation = $this->createMemberNation(['nation_name' => 'Second Member']);
        $this->createCalculationInputs();

        $this->actingAs($manager)
            ->post(route('admin.audits.city-builds.recommendations.regenerate-all'))
            ->assertRedirect()
            ->assertSessionHas('alert-message', 'Queued build recommendations for 2 members.');

        Queue::assertPushed(RefreshNationBuildRecommendationJob::class, 2);
        Queue::assertPushed(
            RefreshNationBuildRecommendationJob::class,
            fn (RefreshNationBuildRecommendationJob $job): bool => $job->nationId === $firstNation->id,
        );
        Queue::assertPushed(
            RefreshNationBuildRecommendationJob::class,
            fn (RefreshNationBuildRecommendationJob $job): bool => $job->nationId === $secondNation->id,
        );
    }

    /** @param list<string> $permissions */
    private function createAdmin(array $permissions = []): User
    {
        $admin = $this->createVerifiedAdmin();
        $this->attachDiscordAccount($admin);

        return $permissions === [] ? $admin : $this->grantPermissions($admin, $permissions);
    }

    /** @param array<string, mixed> $overrides */
    private function createMemberNation(array $overrides = []): Nation
    {
        return Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'vacation_mode_turns' => 0,
            'nation_name' => 'Knights Paradise',
            'leader_name' => 'Test Leader',
            'num_cities' => 1,
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createCity(Nation $nation, array $overrides = []): City
    {
        return City::query()->create([
            ...array_fill_keys(EconomyRules::BUILD_FIELDS, 0),
            'nation_id' => $nation->id,
            'name' => 'Paradise City',
            'date' => now()->toDateString(),
            'infrastructure' => 2000,
            'land' => 1500,
            'powered' => true,
            ...$overrides,
        ]);
    }

    private function createRecommendation(Nation $nation): NationBuildRecommendation
    {
        return NationBuildRecommendation::query()->create([
            'nation_id' => $nation->id,
            'alliance_id' => $nation->alliance_id,
            'model_version' => EconomyRules::MODEL_VERSION,
            'recommended_build_json' => [
                'infra_needed' => 2000,
                'imp_total' => 1,
                'imp_nuclearpower' => 1,
            ],
            'infra_needed' => 2000,
            'land_used' => 1500,
            'imp_total' => 1,
            'converted_profit_per_day' => 125000,
            'resource_profit_per_day' => [],
            'calculated_at' => now(),
        ]);
    }

    private function createCalculationInputs(): MarketPriceSnapshot
    {
        $marketSnapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'test prices',
            'window_started_at' => now()->subDay(),
            'window_ended_at' => now(),
            'calculated_at' => now(),
        ]);
        $marketSnapshot->items()->createMany(collect(EconomyRules::TRADE_RESOURCES)
            ->map(fn (string $resource): array => [
                'resource' => $resource,
                'acquisition_price' => 100,
                'liquidation_price' => 90,
            ])->all());
        RadiationSnapshot::query()->create([
            'snapshot_at' => now(),
            'game_date' => now()->addYears(100)->toDateString(),
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);

        return $marketSnapshot;
    }
}
