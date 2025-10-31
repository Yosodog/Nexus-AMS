<?php

namespace App\Jobs;

use App\Models\War;
use App\Services\AllianceMembershipService;
use App\Services\WarQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job that synchronises a page of war records for the configured alliance context.
 */
class SyncWarsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, Batchable;

    public function __construct(public int $page, public int $perPage)
    {
    }

    private CarbonImmutable $syncTimestamp;
    private string $syncTimestampString;

    /**
     * Keep war upserts aligned with the other sync jobs to minimise DB contention.
     */
    private const UPSERT_CHUNK_SIZE = 1000;

    private const TABLE_WARS = 'wars';

    private const WAR_COLUMNS = [
        'id',
        'date',
        'end_date',
        'reason',
        'war_type',
        'ground_control',
        'air_superiority',
        'naval_blockade',
        'winner_id',
        'turns_left',
        'att_id',
        'att_alliance_id',
        'att_alliance_position',
        'def_id',
        'def_alliance_id',
        'def_alliance_position',
        'att_points',
        'def_points',
        'att_peace',
        'def_peace',
        'att_resistance',
        'def_resistance',
        'att_fortify',
        'def_fortify',
        'att_gas_used',
        'def_gas_used',
        'att_mun_used',
        'def_mun_used',
        'att_alum_used',
        'def_alum_used',
        'att_steel_used',
        'def_steel_used',
        'att_infra_destroyed',
        'def_infra_destroyed',
        'att_money_looted',
        'def_money_looted',
        'def_soldiers_lost',
        'att_soldiers_lost',
        'def_tanks_lost',
        'att_tanks_lost',
        'def_aircraft_lost',
        'att_aircraft_lost',
        'def_ships_lost',
        'att_ships_lost',
        'att_missiles_used',
        'def_missiles_used',
        'att_nukes_used',
        'def_nukes_used',
        'att_infra_destroyed_value',
        'def_infra_destroyed_value',
    ];

    private const WAR_UPDATE_COLUMNS = [
        'date',
        'end_date',
        'reason',
        'war_type',
        'ground_control',
        'air_superiority',
        'naval_blockade',
        'winner_id',
        'turns_left',
        'att_id',
        'att_alliance_id',
        'att_alliance_position',
        'def_id',
        'def_alliance_id',
        'def_alliance_position',
        'att_points',
        'def_points',
        'att_peace',
        'def_peace',
        'att_resistance',
        'def_resistance',
        'att_fortify',
        'def_fortify',
        'att_gas_used',
        'def_gas_used',
        'att_mun_used',
        'def_mun_used',
        'att_alum_used',
        'def_alum_used',
        'att_steel_used',
        'def_steel_used',
        'att_infra_destroyed',
        'def_infra_destroyed',
        'att_money_looted',
        'def_money_looted',
        'def_soldiers_lost',
        'att_soldiers_lost',
        'def_tanks_lost',
        'att_tanks_lost',
        'def_aircraft_lost',
        'att_aircraft_lost',
        'def_ships_lost',
        'att_ships_lost',
        'att_missiles_used',
        'def_missiles_used',
        'att_nukes_used',
        'def_nukes_used',
        'att_infra_destroyed_value',
        'def_infra_destroyed_value',
        'updated_at',
    ];

    /**
     * Execute the war sync for the configured page.
     *
     * We normalise the payload once, reuse cached column templates, and lean on the query builder
     * for the actual upsert so we spend our CPU cycles on SQL execution rather than PHP array churn.
     *
     * @return void
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncWarsJob for page {$this->page} was cancelled.");
            return;
        }

        try {
            $this->syncTimestamp = now()->toImmutable();
            $this->syncTimestampString = $this->syncTimestamp->toDateTimeString();

            $membershipService = app(AllianceMembershipService::class);
            $wars = WarQueryService::getMultipleWars([
                'page' => $this->page,
                'active' => false,
                'alliance_id' => $membershipService->getPrimaryAllianceId(),
            ], $this->perPage, pagination: true, handlePagination: false);

            if (empty($wars)) {
                Log::warning("SyncWarsJob received no wars for page {$this->page}.");

                return;
            }

            $records = [];
            $ids = [];

            foreach ($wars as $war) {
                // Normalise and flatten each payload exactly once before we touch the database.
                $record = $this->extractWarData($war);
                $records[] = $record;
                $ids[] = $record['id'];
            }

            if (!empty($records)) {
                foreach (array_chunk($records, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DB::table(self::TABLE_WARS)->upsert($chunk, ['id'], self::WAR_UPDATE_COLUMNS);
                }
            }

            // Store the IDs processed on this page so the finalizer can detect gaps or missing rows.
            Cache::put("sync_batch:{$this->batchId}:{$this->page}", $ids, now()->addHours(1));

            Cache::add("sync_batch:{$this->batchId}:wars_processed", 0, now()->addHours(6));
            Cache::increment("sync_batch:{$this->batchId}:wars_processed", count($records));

            unset($wars, $records, $ids);
            gc_collect_cycles();
        } catch (Throwable $e) {
            Log::error("Failed to sync wars: {$e->getMessage()}");
        }
    }

    /**
     * Build the persistence payload for a single war row.
     */
    private function extractWarData(mixed $war): array
    {
        $warArray = is_array($war) ? $war : (array) $war;

        if (isset($warArray['att_soldiers_killed'])) {
            $warArray = War::normalizeDeprecatedKilledFields($warArray);
        }

        $data = $this->mapValues($warArray, self::WAR_COLUMNS);
        $data['date'] = $this->normalizeTimestamp($warArray['date'] ?? null);
        $data['end_date'] = $this->normalizeTimestamp($warArray['end_date'] ?? null);
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;

        return $data;
    }

    /**
     * Project the requested columns from the war payload onto a cached template.
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

    private function normalizeTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateTimeString();
    }
}
