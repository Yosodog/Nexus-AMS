<?php

/*
 * SyncNationsJob.php
 *
 * This job is responsible for synchronizing nation data by fetching details from an external API and persisting it into the local database.
 * It transforms raw API responses into structured arrays for nations, resources, military, and cities data,
 * and then performs bulk upserts to efficiently update or insert records.
 */

namespace App\Jobs;

use App\Services\NationQueryService;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queue job that synchronizes a slice of nation, resource, military, and city data.
 *
 * The GraphQL gateway provides each page sequentially, so the job focuses purely on
 * transforming the payload into DB-ready shapes and performing high-volume upserts.
 */
class SyncNationsJob implements ShouldQueue
{
    use Queueable, Batchable;

    /**
     * Allow the heavy nation sync to run to completion even on slow queues.
     */
    public $timeout = 99999999;

    /**
     * Chunk size for bulk upsert statements. 1k rows keeps MySQL packets small while
     * amortising query preparation overhead.
     */
    private const UPSERT_CHUNK_SIZE = 1000;

    /**
     * Pagination parameters passed in from the console command dispatcher.
     */
    public int $page;
    public int $perPage;

    private CarbonImmutable $syncTimestamp;
    private string $syncTimestampString;

    private const TABLE_NATIONS = 'nations';
    private const TABLE_NATION_RESOURCES = 'nation_resources';
    private const TABLE_NATION_MILITARY = 'nation_military';
    private const TABLE_CITIES = 'cities';

    private const NATION_COLUMNS = [
        'id',
        'alliance_id',
        'alliance_position',
        'alliance_position_id',
        'nation_name',
        'leader_name',
        'continent',
        'war_policy_turns',
        'domestic_policy_turns',
        'color',
        'num_cities',
        'score',
        'update_tz',
        'population',
        'flag',
        'vacation_mode_turns',
        'beige_turns',
        'espionage_available',
        'discord',
        'discord_id',
        'turns_since_last_city',
        'turns_since_last_project',
        'projects',
        'project_bits',
        'wars_won',
        'wars_lost',
        'tax_id',
        'alliance_seniority',
        'gross_national_income',
        'gross_domestic_product',
        'vip',
        'commendations',
        'denouncements',
        'offensive_wars_count',
        'defensive_wars_count',
        'money_looted',
        'total_infrastructure_destroyed',
        'total_infrastructure_lost',
    ];

    private const NATION_UPDATE_COLUMNS = [
        'alliance_id',
        'alliance_position',
        'alliance_position_id',
        'nation_name',
        'leader_name',
        'continent',
        'war_policy_turns',
        'domestic_policy_turns',
        'color',
        'num_cities',
        'score',
        'update_tz',
        'population',
        'flag',
        'vacation_mode_turns',
        'beige_turns',
        'espionage_available',
        'discord',
        'discord_id',
        'turns_since_last_city',
        'turns_since_last_project',
        'projects',
        'project_bits',
        'wars_won',
        'wars_lost',
        'tax_id',
        'alliance_seniority',
        'gross_national_income',
        'gross_domestic_product',
        'vip',
        'commendations',
        'denouncements',
        'offensive_wars_count',
        'defensive_wars_count',
        'money_looted',
        'total_infrastructure_destroyed',
        'total_infrastructure_lost',
        'updated_at',
    ];

    private const RESOURCE_COLUMNS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'credits',
    ];

    private const RESOURCE_UPDATE_COLUMNS = [
        'money',
        'coal',
        'oil',
        'uranium',
        'iron',
        'bauxite',
        'lead',
        'gasoline',
        'munitions',
        'steel',
        'aluminum',
        'food',
        'credits',
        'updated_at',
    ];

    private const MILITARY_COLUMNS = [
        'soldiers',
        'tanks',
        'aircraft',
        'ships',
        'missiles',
        'nukes',
        'spies',
        'soldiers_today',
        'tanks_today',
        'aircraft_today',
        'ships_today',
        'missiles_today',
        'nukes_today',
        'spies_today',
        'soldier_casualties',
        'soldier_kills',
        'tank_casualties',
        'tank_kills',
        'aircraft_casualties',
        'aircraft_kills',
        'ship_casualties',
        'ship_kills',
        'missile_casualties',
        'missile_kills',
        'nuke_casualties',
        'nuke_kills',
        'spy_casualties',
        'spy_kills',
        'spy_attacks',
    ];

    private const MILITARY_UPDATE_COLUMNS = [
        'soldiers',
        'tanks',
        'aircraft',
        'ships',
        'missiles',
        'nukes',
        'spies',
        'soldiers_today',
        'tanks_today',
        'aircraft_today',
        'ships_today',
        'missiles_today',
        'nukes_today',
        'spies_today',
        'soldier_casualties',
        'soldier_kills',
        'tank_casualties',
        'tank_kills',
        'aircraft_casualties',
        'aircraft_kills',
        'ship_casualties',
        'ship_kills',
        'missile_casualties',
        'missile_kills',
        'nuke_casualties',
        'nuke_kills',
        'spy_casualties',
        'spy_kills',
        'spy_attacks',
        'updated_at',
    ];

    private const CITY_COLUMNS = [
        'id',
        'nation_id',
        'name',
        'date',
        'infrastructure',
        'land',
        'powered',
        'oil_power',
        'wind_power',
        'coal_power',
        'nuclear_power',
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'barracks',
        'farm',
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'oil_refinery',
        'aluminum_refinery',
        'steel_mill',
        'munitions_factory',
        'factory',
        'hangar',
        'drydock',
    ];

    private const CITY_UPDATE_COLUMNS = [
        'nation_id',
        'name',
        'date',
        'infrastructure',
        'land',
        'powered',
        'oil_power',
        'wind_power',
        'coal_power',
        'nuclear_power',
        'coal_mine',
        'oil_well',
        'uranium_mine',
        'barracks',
        'farm',
        'police_station',
        'hospital',
        'recycling_center',
        'subway',
        'supermarket',
        'bank',
        'shopping_mall',
        'stadium',
        'lead_mine',
        'iron_mine',
        'bauxite_mine',
        'oil_refinery',
        'aluminum_refinery',
        'steel_mill',
        'munitions_factory',
        'factory',
        'hangar',
        'drydock',
        'updated_at',
    ];

    /**
     * Create a new job instance.
     *
     * @param int $page    The current GraphQL page being synchronised.
     * @param int $perPage The number of nations returned per page.
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Execute the job.
     *
     * The bulk of the CPU work here is transforming the nested GraphQL payload into flat tables.
     * We keep the loop tight and reuse column templates so we only perform array bookkeeping once
     * per chunk rather than per row.
     *
     * @return void
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncNationsJob for page {$this->page} was cancelled.");
            return;
        }

        try {
            $this->syncTimestamp = now()->toImmutable();
            $this->syncTimestampString = $this->syncTimestamp->toDateTimeString();

            // Fetch nations from the API using the NationQueryService with pagination parameters
            $nations = NationQueryService::getMultipleNations(
                ["page" => $this->page],
                $this->perPage,
                true,
                handlePagination: false
            );

            if (empty($nations)) {
                Log::warning("SyncNationsJob received no nations for page {$this->page}.");

                return;
            }

            // Initialize arrays to hold transformed data for nations, resources, military, and cities
            $nationData = [];
            $resourcesData = [];
            $militaryData = [];
            $citiesData = [];

            // Process each nation and extract necessary data
            foreach ($nations as $nation) {
                // Convert objects coming back from GraphQL into arrays once to minimise property access costs.
                $nationArray = is_array($nation) ? $nation : (array) $nation;

                // Extract nation core data
                $nationData[] = $this->extractNationData($nationArray);

                // Extract resource data if available
                $resourceData = $this->extractResourceData($nationArray);
                if (!is_null($resourceData)) {
                    $resourcesData[] = $resourceData;
                }

                // Extract military data
                $militaryDataArray = $this->extractMilitaryData($nationArray);
                if (!empty($militaryDataArray)) {
                    $militaryData[] = $militaryDataArray;
                }

                // Extract cities data
                $citiesDataArray = $this->extractCitiesData($nationArray);
                if (!empty($citiesDataArray)) {
                    foreach ($citiesDataArray as $cityData) {
                        $citiesData[] = $cityData;
                    }
                }
            }

            // Perform bulk upsert operations in a single transaction for improved performance and atomicity
            $this->bulkUpsert($nationData, $resourcesData, $militaryData, $citiesData);

            $this->recordProcessedCount(count($nationData));

            // Clean up variables and force garbage collection to free memory
            unset($nations, $nationData, $resourcesData, $militaryData, $citiesData);
            gc_collect_cycles();
        } catch (Exception $e) {
            // Log any exceptions encountered during the synchronization process
            Log::error('Failed to fetch nations batch', [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'exception' => $e,
            ]);
        }

    }

    /**
     * Extract nation core data from a nation payload.
     *
     * @return array<string, mixed>
     */
    private function extractNationData(array $nation): array
    {
        $data = $this->mapValues($nation, self::NATION_COLUMNS);
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;

        return $data;
    }

    /**
     * Extract resource data from a nation payload.
     *
     * @return array<string, mixed>|null
     */
    private function extractResourceData(array $nation): ?array
    {
        if (!array_key_exists('money', $nation) || $nation['money'] === null) {
            return null;
        }
        $data = $this->mapValues($nation, self::RESOURCE_COLUMNS);
        $data['nation_id'] = $nation['id'];
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;

        return $data;
    }

    /**
     * Extract military data from a nation payload.
     *
     * @return array<string, mixed>
     */
    private function extractMilitaryData(array $nation): array
    {
        $data = $this->mapValues($nation, self::MILITARY_COLUMNS);
        $data['nation_id'] = $nation['id'];
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;

        return $data;
    }

    /**
     * Extract cities data from a nation payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractCitiesData(array $nation): array
    {
        $cities = [];
        if (empty($nation['cities'])) {
            return $cities;
        }
        foreach ($nation['cities'] as $city) {
            $cityArray = is_array($city) ? $city : (array) $city;
            $cityData = $this->mapValues($cityArray, self::CITY_COLUMNS);
            $cityData['nation_id'] = $nation['id'];
            $cityData['updated_at'] = $this->syncTimestampString;
            $cityData['created_at'] = $this->syncTimestampString;
            $cities[] = $cityData;
        }
        return $cities;
    }

    /**
     * Perform bulk upsert operations within a database transaction.
     *
     * @param array $nationData
     * @param array $resourcesData
     * @param array $militaryData
     * @param array $citiesData
     */
    private function bulkUpsert(array $nationData, array $resourcesData, array $militaryData, array $citiesData): void
    {
        DB::transaction(function () use ($nationData, $resourcesData, $militaryData, $citiesData) {
            if (!empty($nationData)) {
                foreach (array_chunk($nationData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    // Query builder upserts avoid the overhead of hydrating Eloquent models per row.
                    DB::table(self::TABLE_NATIONS)->upsert($chunk, ['id'], self::NATION_UPDATE_COLUMNS);
                }
            }
            if (!empty($resourcesData)) {
                foreach (array_chunk($resourcesData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DB::table(self::TABLE_NATION_RESOURCES)->upsert($chunk, ['nation_id'], self::RESOURCE_UPDATE_COLUMNS);
                }
            }
            if (!empty($militaryData)) {
                foreach (array_chunk($militaryData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DB::table(self::TABLE_NATION_MILITARY)->upsert($chunk, ['nation_id'], self::MILITARY_UPDATE_COLUMNS);
                }
            }
            if (!empty($citiesData)) {
                foreach (array_chunk($citiesData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                    DB::table(self::TABLE_CITIES)->upsert($chunk, ['id'], self::CITY_UPDATE_COLUMNS);
                }
            }
        });
    }

    /**
     * Map the selected columns from the source payload onto a null-filled template.
     *
     * Using cached templates keeps us from rebuilding the column list on every row and greatly
     * reduces per-record CPU churn compared to repeated foreach assignment.
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
     * Track how many records were persisted so the finalizer knows the batch made progress.
     */
    private function recordProcessedCount(int $count): void
    {
        if (!isset($this->batchId) || $count === 0) {
            return;
        }

        $cacheKey = "sync_batch:{$this->batchId}:nations_processed";

        Cache::add($cacheKey, 0, now()->addHours(6));
        Cache::increment($cacheKey, $count);
    }
}
