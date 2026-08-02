<?php

namespace Tests\Feature\Console;

use App\DataTransferObjects\MarketPriceSet;
use App\Exceptions\ProfitabilityContextUnavailable;
use App\Jobs\RefreshNationBuildRecommendationJob;
use App\Models\RadiationSnapshot;
use App\Services\NationBuildRecommendationService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RefreshBuildRecommendationsCommandTest extends TestCase
{
    #[Test]
    public function it_queues_each_nation_with_fixed_input_snapshot_ids(): void
    {
        Queue::fake();
        $service = Mockery::mock(NationBuildRecommendationService::class);
        $service->shouldReceive('getPriceSetForBatch')->once()->andReturn(new MarketPriceSet(
            acquisitionPrices: [],
            liquidationPrices: [],
            snapshotId: 42,
        ));
        $radiationSnapshot = new RadiationSnapshot(['id' => 7, 'game_date' => '2126-09-21']);
        $service->shouldReceive('getRadiationSnapshotForBatch')->once()->andReturn($radiationSnapshot);
        $service->shouldReceive('eligibleNationIds')->once()->andReturn([1001, 1002]);
        $service->shouldReceive('assertBatchCalculationContextAvailable')
            ->once()
            ->with([1001, 1002], $radiationSnapshot);
        $service->shouldReceive('pruneIneligibleRecommendations')->once()->with([1001, 1002]);
        $this->app->instance(NationBuildRecommendationService::class, $service);

        $this->artisan('build-recommendations:refresh')
            ->expectsOutput('Queued build recommendations for 2 nations.')
            ->assertSuccessful();

        Queue::assertPushed(RefreshNationBuildRecommendationJob::class, 2);
        Queue::assertPushed(
            RefreshNationBuildRecommendationJob::class,
            fn (RefreshNationBuildRecommendationJob $job): bool => $job->nationId === 1001
                && $job->marketPriceSnapshotId === 42
                && $job->radiationSnapshotId === 7
        );
    }

    #[Test]
    public function it_keeps_existing_recommendations_and_queues_nothing_when_context_is_unavailable(): void
    {
        Queue::fake();
        $service = Mockery::mock(NationBuildRecommendationService::class);
        $service->shouldReceive('getPriceSetForBatch')->once()->andReturn(new MarketPriceSet(
            acquisitionPrices: [],
            liquidationPrices: [],
            snapshotId: 42,
        ));
        $radiationSnapshot = new RadiationSnapshot(['id' => 7, 'game_date' => '2126-09-21']);
        $service->shouldReceive('getRadiationSnapshotForBatch')->once()->andReturn($radiationSnapshot);
        $service->shouldReceive('eligibleNationIds')->once()->andReturn([1001, 1002]);
        $service->shouldReceive('assertBatchCalculationContextAvailable')
            ->once()
            ->with([1001, 1002], $radiationSnapshot)
            ->andThrow(new ProfitabilityContextUnavailable);
        $service->shouldNotReceive('pruneIneligibleRecommendations');
        $this->app->instance(NationBuildRecommendationService::class, $service);

        $this->artisan('build-recommendations:refresh')
            ->expectsOutput('No build recommendations were queued. Existing recommendations remain active.')
            ->assertFailed();

        Queue::assertNothingPushed();
    }
}
