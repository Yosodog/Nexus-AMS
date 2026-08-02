<?php

namespace Tests\Feature;

use App\Http\Middleware\DiscordVerifiedMiddleware;
use App\Http\Middleware\EnsureMfaConfigured;
use App\Http\Middleware\EnsureUserIsVerified;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\MarketPriceSnapshot;
use App\Models\Nation;
use App\Models\NationBuildRecommendation;
use App\Models\RadiationSnapshot;
use App\Models\User;
use App\Services\Economy\EconomyRules;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildRecommendationRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::forever('alliances:membership:ids', [777]);
        Queue::fake();
        $marketSnapshot = MarketPriceSnapshot::query()->create([
            'basis' => 'test prices',
            'window_started_at' => now()->subDays(7),
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
            'game_date' => '2126-09-21',
            'global' => 0,
            'north_america' => 0,
            'south_america' => 0,
            'europe' => 0,
            'africa' => 0,
            'asia' => 0,
            'australia' => 0,
            'antarctica' => 0,
        ]);

        $this->withoutMiddleware([
            EnsureUserIsVerified::class,
            DiscordVerifiedMiddleware::class,
            EnsureMfaConfigured::class,
        ]);
    }

    public function test_regeneration_is_rate_limited_per_user(): void
    {
        $user = $this->createMember();

        $this->actingAs($user)
            ->post(route('audit.recommendation.regenerate'))
            ->assertRedirect(route('audit.index'));

        $this->post(route('audit.recommendation.regenerate'))
            ->assertRedirect(route('audit.index'));

        $this->post(route('audit.recommendation.regenerate'))
            ->assertTooManyRequests();

        Queue::assertPushed(RefreshNationBuildRecommendationJob::class, 1);
        Queue::assertPushed(
            RefreshNationBuildRecommendationJob::class,
            fn (RefreshNationBuildRecommendationJob $job): bool => $job->marketPriceSnapshotId !== null
                && $job->radiationSnapshotId !== null
        );
    }

    public function test_regeneration_jobs_are_unique_per_nation(): void
    {
        $job = new RefreshNationBuildRecommendationJob(123);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('nation-build-recommendation:123', $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
    }

    public function test_legacy_recommendation_is_hidden_as_recalculation_pending(): void
    {
        $user = $this->createMember();
        NationBuildRecommendation::query()->create([
            'nation_id' => $user->nation_id,
            'alliance_id' => 777,
            'model_version' => 1,
            'recommended_build_json' => ['infra_needed' => 2500],
            'resource_profit_per_day' => [],
            'calculated_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('The current calculation is pending.')
            ->assertDontSee('Highest recovered city target');
    }

    private function createMember(): User
    {
        $nation = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'treasure_income_modifier' => 0,
            'color_turn_bonus' => 0,
            'economy_context_synced_at' => now(),
        ]);

        return User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);
    }
}
