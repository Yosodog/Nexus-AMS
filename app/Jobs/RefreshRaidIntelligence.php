<?php

namespace App\Jobs;

use App\Services\RaidIntelligenceRefreshService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RefreshRaidIntelligence implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    /** @param list<int> $nationIds */
    public function __construct(public array $nationIds)
    {
        sort($this->nationIds);
        $this->onQueue(config('raids.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', implode(',', $this->nationIds));
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('raids.retry_until_minutes', 15));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('raid-intelligence:world-refresh'))->shared()->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(RaidIntelligenceRefreshService $service): void
    {
        $service->refresh($this->nationIds);
    }
}
