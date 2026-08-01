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

    public $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(private readonly int $nationId) {}

    public function uniqueId(): string
    {
        return 'nation-build-recommendation:'.$this->nationId;
    }

    public function handle(NationBuildRecommendationService $recommendationService): void
    {
        $recommendationService->refreshStoredRecommendationForNationId($this->nationId);
    }
}
