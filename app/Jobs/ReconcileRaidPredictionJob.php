<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RaidOutcomeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcileRaidPredictionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public readonly int $warId)
    {
        $this->onQueue((string) config('raids.queue', 'default'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(RaidOutcomeService $outcomes): void
    {
        $outcomes->reconcile($this->warId);
    }
}
