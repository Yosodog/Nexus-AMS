<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RaidPredictionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class EvaluateRaidPredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $predictionId)
    {
        $this->onQueue((string) config('raids.queue', 'default'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(RaidPredictionService $predictions): void
    {
        $predictions->evaluate($this->predictionId);
    }
}
