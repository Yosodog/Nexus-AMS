<?php

namespace App\Jobs;

use App\Services\NationBuildRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshNationBuildRecommendationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Fits one recommendation on a contended single CPU and stays below the Redis queue
     * retry_after (150 seconds), so a slow job times out before it could run twice.
     */
    public $timeout = 120;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $nationId,
        public readonly ?int $marketPriceSnapshotId = null,
        public readonly ?int $radiationSnapshotId = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'nation-build-recommendation:'.$this->nationId;
    }

    public function handle(NationBuildRecommendationService $recommendationService): void
    {
        $recommendationService->refreshStoredRecommendationForNationId(
            $this->nationId,
            $this->marketPriceSnapshotId,
            $this->radiationSnapshotId
        );
    }
}
