<?php

namespace App\Jobs;

use App\Domain\Milcom\Enums\RecommendationRunStatus;
use App\Models\MilcomOperation;
use App\Models\MilcomRecommendationRun;
use App\Services\Milcom\RecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
        $run = DB::transaction(function (): ?MilcomRecommendationRun {
            $runReference = MilcomRecommendationRun::query()->findOrFail($this->recommendationRunId);
            $operation = MilcomOperation::query()
                ->lockForUpdate()
                ->findOrFail($runReference->operation_id);
            $run = MilcomRecommendationRun::query()
                ->lockForUpdate()
                ->findOrFail($this->recommendationRunId);

            if (! $operation->federation_action_required) {
                return $run;
            }

            if (in_array($run->status, [
                RecommendationRunStatus::Queued,
                RecommendationRunStatus::Running,
            ], true)) {
                $run->forceFill([
                    'status' => RecommendationRunStatus::Superseded,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ])->save();
            }

            return null;
        }, attempts: 3);

        if ($run === null) {
            return;
        }

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
