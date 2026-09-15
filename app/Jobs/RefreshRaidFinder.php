<?php

namespace App\Jobs;

use App\Services\RaidFinderCache;
use App\Services\RaidFinderService;
use App\Services\RuntimeCapabilities;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Throwable;

class RefreshRaidFinder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $nationId, public string $lockOwner)
    {
        $this->onQueue((string) config('raids.queue', 'default'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(RaidFinderService $finder, RaidFinderCache $cache, RuntimeCapabilities $capabilities): void
    {
        Telescope::withoutRecording(fn () => $this->refresh($finder, $cache, $capabilities));
    }

    private function refresh(RaidFinderService $finder, RaidFinderCache $cache, RuntimeCapabilities $capabilities): void
    {
        $lock = Cache::restoreLock($cache->lockKey($this->nationId), $this->lockOwner);
        if (! $lock->isOwnedByCurrentProcess()) {
            return;
        }
        if (! $capabilities->writesTenantPrivate()) {
            $lock->release();

            return;
        }
        $revision = $cache->key($this->nationId);
        $targets = $finder->findTargets($this->nationId, function (Collection $partial) use ($cache, $revision): void {
            $cache->store($this->nationId, $partial->toArray(), $revision, complete: false);
        });
        $cache->store($this->nationId, $targets->toArray(), $revision);
        Cache::forget($cache->failureKey($this->nationId));
        $lock->release();
    }

    public function failed(?Throwable $exception): void
    {
        $cache = app(RaidFinderCache::class);
        Cache::put($cache->failureKey($this->nationId), true, 60);
        Cache::restoreLock($cache->lockKey($this->nationId), $this->lockOwner)->release();
        Log::warning('Queued raid finder evaluation failed.', [
            'nation_id' => $this->nationId, 'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
