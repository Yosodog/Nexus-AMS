<?php

namespace App\Jobs;

use App\Models\City;
use App\Models\Nation;
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Services\SettingService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinalizeNationSyncJob implements ShouldQueue
{
    use Queueable;

    public string $batchId;

    public function __construct(string $batchId)
    {
        $this->batchId = $batchId;
    }

    public function handle(): void
    {
        $batch = Bus::findBatch($this->batchId);
        $mode = $this->determineMode($batch);

        if (! $batch) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} could not be found.");
            $this->recordBatchId($mode);

            return;
        }

        if ($batch->cancelled()) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} was cancelled.");
            $this->recordBatchId($mode);

            return;
        }

        if ($batch->failedJobs > 0) {
            Log::warning("FinalizeNationSyncJob skipped — batch {$this->batchId} had {$batch->failedJobs} failure(s).");
            $this->recordBatchId($mode);

            return;
        }

        $cutoff = now()->subDays(30);

        $staleNationIds = Nation::query()
            ->where('updated_at', '<', $cutoff)
            ->pluck('id');
        $staleNationCount = $staleNationIds->count();
        $totalNationCount = Nation::count();

        if ($totalNationCount > 0 && $staleNationCount >= $totalNationCount) {
            Log::error("FinalizeNationSyncJob aborted — stale nation count ({$staleNationCount}) matched total nations ({$totalNationCount}).");
            $this->recordBatchId($mode);

            return;
        }

        foreach ($staleNationIds->chunk(1000) as $nationIds) {
            DB::transaction(function () use ($nationIds, $cutoff): void {
                $stillStaleNationIds = Nation::query()
                    ->whereKey($nationIds)
                    ->where('updated_at', '<', $cutoff)
                    ->lockForUpdate()
                    ->pluck('id');

                if ($stillStaleNationIds->isEmpty()) {
                    return;
                }

                NationResources::whereIn('nation_id', $stillStaleNationIds)->delete();
                NationMilitary::whereIn('nation_id', $stillStaleNationIds)->delete();
                City::whereIn('nation_id', $stillStaleNationIds)->delete();
                Nation::whereKey($stillStaleNationIds)
                    ->where('updated_at', '<', $cutoff)
                    ->delete();
            });
        }

        Log::info("🧹 FinalizeNationSyncJob: Soft-deleted {$staleNationCount} nations not updated since {$cutoff->toDateTimeString()} after {$batch->totalJobs} completed pages.");

        $this->recordBatchId($mode);
    }

    private function determineMode(?Batch $batch): string
    {
        $mode = $batch?->options['mode'] ?? null;

        if ($mode === 'rolling') {
            return 'rolling';
        }

        if ($mode === 'manual') {
            return 'manual';
        }

        if (SettingService::getLastRollingNationSyncBatchId() === $this->batchId) {
            return 'rolling';
        }

        return 'manual';
    }

    private function recordBatchId(string $mode): void
    {
        if ($mode === 'rolling') {
            SettingService::setLastRollingNationSyncBatchId($this->batchId);

            return;
        }

        SettingService::setLastManualNationSyncBatchId($this->batchId);
    }
}
