<?php

namespace App\Jobs;

use App\Services\NationBuildRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Refreshes the build recommendations of a batch of nations one nation at a time.
 *
 * Each job refreshes the first nation in its list and then queues a new job for the rest, at the
 * back of the queue. At most one job of a batch is therefore running at any moment, so a batch
 * can never occupy every worker on its queue, and other work queued during the batch runs in
 * between. Starting a new batch supersedes any batch still in progress.
 */
class RefreshBuildRecommendationBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CURRENT_BATCH_CACHE_KEY = 'build-recommendations:current-batch';

    private const CONTINUATION_CACHE_PREFIX = 'build-recommendations:batch-continuation:';

    private const BATCH_CACHE_TTL_HOURS = 6;

    /**
     * Stays below the Redis queue retry_after (150 seconds) so a slow job is never run twice.
     */
    public int $timeout = 120;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [30];

    /**
     * @param  list<int>  $nationIds
     */
    public function __construct(
        public readonly array $nationIds,
        public readonly int $marketPriceSnapshotId,
        public readonly int $radiationSnapshotId,
        public readonly string $batchToken,
    ) {
        $this->onQueue('default');
    }

    /**
     * Supersede any running batch and queue the first nation of a new one.
     *
     * @param  list<int>  $nationIds
     */
    public static function start(array $nationIds, int $marketPriceSnapshotId, int $radiationSnapshotId): void
    {
        $nationIds = array_values(array_unique(array_map('intval', $nationIds)));

        if ($nationIds === []) {
            throw new InvalidArgumentException('A build recommendation batch needs at least one nation.');
        }

        $batchToken = (string) Str::uuid();
        Cache::put(self::CURRENT_BATCH_CACHE_KEY, $batchToken, now()->addHours(self::BATCH_CACHE_TTL_HOURS));

        self::dispatch($nationIds, $marketPriceSnapshotId, $radiationSnapshotId, $batchToken);
    }

    public function handle(NationBuildRecommendationService $recommendationService): void
    {
        if (! $this->belongsToCurrentBatch() || $this->nationIds === []) {
            return;
        }

        $recommendationService->refreshStoredRecommendationForNationId(
            $this->nationIds[0],
            $this->marketPriceSnapshotId,
            $this->radiationSnapshotId
        );

        $this->queueRemainingNations();
    }

    /**
     * Keep the batch moving when this nation has used all of its attempts.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Build recommendation refresh failed; continuing the batch without this nation', [
            'nation_id' => $this->nationIds[0] ?? null,
            'remaining_nations' => max(0, count($this->nationIds) - 1),
            'batch_token' => $this->batchToken,
            'exception' => $exception?->getMessage(),
        ]);

        if ($this->belongsToCurrentBatch()) {
            $this->queueRemainingNations();
        }
    }

    private function belongsToCurrentBatch(): bool
    {
        $currentBatchToken = Cache::get(self::CURRENT_BATCH_CACHE_KEY);

        return $currentBatchToken === null || $currentBatchToken === $this->batchToken;
    }

    /**
     * Queue the next job once, even when this job is retried after it already queued it.
     */
    private function queueRemainingNations(): void
    {
        $remainingNationIds = array_slice($this->nationIds, 1);

        if ($remainingNationIds === []) {
            return;
        }

        $continuationKey = self::CONTINUATION_CACHE_PREFIX.$this->batchToken.':'.$remainingNationIds[0];

        if (! Cache::add($continuationKey, true, now()->addHours(self::BATCH_CACHE_TTL_HOURS))) {
            return;
        }

        self::dispatch(
            $remainingNationIds,
            $this->marketPriceSnapshotId,
            $this->radiationSnapshotId,
            $this->batchToken
        );
    }
}
