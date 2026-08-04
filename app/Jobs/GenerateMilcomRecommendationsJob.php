<?php

namespace App\Jobs;

use App\Models\MilcomRecommendationRun;
use App\Services\Milcom\RecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMilcomRecommendationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $recommendationRunId) {}

    public function uniqueId(): string
    {
        return "milcom-recommendation-run:{$this->recommendationRunId}";
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        $run = MilcomRecommendationRun::query()->find($this->recommendationRunId);
        $tags = ["milcom:run:{$this->recommendationRunId}"];

        if ($run !== null) {
            $tags[] = "milcom:operation:{$run->operation_id}";

            if ($run->objective_id !== null) {
                $tags[] = "milcom:objective:{$run->objective_id}";
            }
        }

        return $tags;
    }

    public function handle(RecommendationEngine $engine): void
    {
        $run = MilcomRecommendationRun::query()->findOrFail($this->recommendationRunId);
        $engine->execute($run);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 10, 30];
    }
}
