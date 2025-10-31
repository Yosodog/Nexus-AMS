<?php

namespace App\Jobs;

use App\Services\AllianceQueryService;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that synchronises a page of alliance data into the local database.
 */
class SyncAlliancesJob implements ShouldQueue
{
    use Queueable, Batchable;

    public int $page;
    public int $perPage;

    private CarbonImmutable $syncTimestamp;
    private string $syncTimestampString;

    /**
     * Chunk size for bulk upserts. Shared with the nation job to keep DB behaviour predictable.
     */
    private const UPSERT_CHUNK_SIZE = 1000;

    private const ALLIANCE_COLUMNS = [
        'id',
        'name',
        'acronym',
        'score',
        'color',
        'average_score',
        'accept_members',
        'flag',
        'forum_link',
        'discord_link',
        'wiki_link',
        'rank',
    ];

    private const ALLIANCE_UPDATE_COLUMNS = [
        'name',
        'acronym',
        'score',
        'color',
        'average_score',
        'accept_members',
        'flag',
        'forum_link',
        'discord_link',
        'wiki_link',
        'rank',
        'updated_at',
    ];

    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Execute the job for the requested page.
     *
     * We read once from the GraphQL gateway, normalise the payload, and then perform high-volume
     * upserts using the query builder to avoid Eloquent model instantiation overhead.
     *
     * @return void
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncAlliancesJob for page {$this->page} was cancelled.");
            return;
        }

        try {
            $this->syncTimestamp = now()->toImmutable();
            $this->syncTimestampString = $this->syncTimestamp->toDateTimeString();

            $alliances = AllianceQueryService::getMultipleAlliances([
                "page" => $this->page
            ], $this->perPage, handlePagination: false);

            if (empty($alliances)) {
                Log::warning("SyncAlliancesJob received no alliances for page {$this->page}.");

                return;
            }

            $records = [];

            foreach ($alliances as $alliance) {
                // Flatten the alliance payload exactly once so we can reuse the array for every table.
                $records[] = $this->extractAllianceData(is_array($alliance) ? $alliance : (array) $alliance);
            }

            if (!empty($records)) {
                foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    // Raw query builder avoids Eloquent hydration and keeps CPU focused on SQL execution.
                    DB::table('alliances')->upsert($chunk, ['id'], self::ALLIANCE_UPDATE_COLUMNS);
                }
            }

            $this->recordProcessedCount(count($records));

            unset($alliances, $records);
            gc_collect_cycles();
        } catch (Exception $e) {
            Log::error("Failed to fetch alliances (page {$this->page}): " . $e->getMessage());
        }
    }

    /**
     * Build the persistence payload for a single alliance row.
     */
    private function extractAllianceData(array $alliance): array
    {
        $data = $this->mapValues($alliance, self::ALLIANCE_COLUMNS);
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;

        return $data;
    }

    /**
     * Project the requested columns from the source payload onto a cached null-filled template.
     *
     * @return array<string, mixed>
     */
    private function mapValues(array $source, array $columns): array
    {
        static $templates = [];

        $key = md5(implode('|', $columns));

        if (!isset($templates[$key])) {
            $templates[$key] = array_fill_keys($columns, null);
        }

        $template = $templates[$key];

        return array_replace($template, array_intersect_key($source, $template));
    }

    /**
     * Track how many alliances were refreshed so the finalize step can validate progress.
     */
    private function recordProcessedCount(int $count): void
    {
        if (!isset($this->batchId) || $count === 0) {
            return;
        }

        $cacheKey = "sync_batch:{$this->batchId}:alliances_processed";

        Cache::add($cacheKey, 0, now()->addHours(6));
        Cache::increment($cacheKey, $count);
    }
}
