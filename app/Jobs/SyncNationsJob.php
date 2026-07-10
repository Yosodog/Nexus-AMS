<?php

/*
 * SyncNationsJob.php
 *
 * This job is responsible for synchronizing nation data by fetching details from an external API and persisting it into the local database.
 * It transforms raw API responses into structured arrays for nations, resources, military, and cities data,
 * and then performs bulk upserts to efficiently update or insert records.
 */

namespace App\Jobs;

use App\Models\City;
use App\Services\NationQueryService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use RuntimeException;
use Throwable;

/**
 * Queue job that synchronizes a slice of nation, resource, military, and city data.
 *
 * The GraphQL gateway provides each page sequentially, so the job focuses purely on
 * transforming the payload into DB-ready shapes and performing high-volume upserts.
 */
class SyncNationsJob implements ShouldQueue
{
    use Batchable, Queueable;

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

    public ?int $minScore;

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
        'war_policy',
        'domestic_policy',
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
        'war_policy',
        'domestic_policy',
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
        'deleted_at',
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
        'deleted_at',
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

    /**
     * The sync only needs persisted fields, not the generic 127-field nation projection.
     */
    private const NATION_QUERY_FIELDS = [
        ...self::NATION_COLUMNS,
        ...self::RESOURCE_COLUMNS,
        ...self::MILITARY_COLUMNS,
    ];

    /**
     * Fields whose GraphQL hydration historically converted missing or null values to zero.
     */
    private const ZERO_DEFAULT_COLUMNS = [
        'vip',
        'commendations',
        'denouncements',
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
        'deleted_at',
    ];

    private const CITY_COLUMNS = [
        'id',
        'nation_id',
        'name',
        'date',
        'nuke_date',
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
        'nuke_date',
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
        'deleted_at',
    ];

    /**
     * Create a new job instance.
     *
     * @param  int  $page  The current GraphQL page being synchronised.
     * @param  int  $perPage  The number of nations returned per page.
     */
    public function __construct(int $page, int $perPage, ?int $minScore = null)
    {
        $this->page = $page;
        $this->perPage = $perPage;
        $this->minScore = $minScore;
        $this->onQueue('sync');
    }

    /**
     * Execute the job.
     *
     * The bulk of the CPU work here is transforming the nested GraphQL payload into flat tables.
     * We keep the loop tight and reuse column templates so we only perform array bookkeeping once
     * per chunk rather than per row.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            Log::info("SyncNationsJob for page {$this->page} was cancelled.");

            return;
        }

        $jobStartedAt = hrtime(true);
        $stage = 'fetch';

        try {
            $this->syncTimestampString = now()->toDateTimeString();

            $filters = ['page' => $this->page];
            if ($this->minScore !== null) {
                $filters['min_score'] = $this->minScore;
            }

            $stageStartedAt = hrtime(true);
            $nations = NationQueryService::getRawNationPage(
                arguments: $filters,
                perPage: $this->perPage,
                nationFields: self::NATION_QUERY_FIELDS,
                cityFields: self::CITY_COLUMNS,
            );
            $apiMilliseconds = $this->elapsedMilliseconds($stageStartedAt);

            if ($nations === []) {
                throw new RuntimeException("Nation sync page {$this->page} returned no records.");
            }

            $stage = 'transform';
            $stageStartedAt = hrtime(true);

            // Initialize arrays to hold transformed data for nations, resources, military, and cities
            $nationData = [];
            $resourcesData = [];
            $militaryData = [];
            $citiesData = [];

            // Process each nation and extract necessary data
            foreach ($nations as $nation) {
                $nation = $this->normalizeNationPayload($nation);

                // Extract nation core data
                $nationData[] = $this->extractNationData($nation);

                // Extract resource data if available
                $resourceData = $this->extractResourceData($nation);
                if (! is_null($resourceData)) {
                    $resourcesData[] = $resourceData;
                }

                // Extract military data
                $militaryData[] = $this->extractMilitaryData($nation);

                // Append cities directly to avoid an intermediate array per nation.
                $this->appendCitiesData($nation, $citiesData);
            }
            $transformMilliseconds = $this->elapsedMilliseconds($stageStartedAt);

            // Perform bulk upsert operations in a single transaction for improved performance and atomicity
            $stage = 'persist';
            $stageStartedAt = hrtime(true);
            $this->bulkUpsert($nationData, $resourcesData, $militaryData, $citiesData);
            $databaseMilliseconds = $this->elapsedMilliseconds($stageStartedAt);

            Log::info('Nation sync page completed', [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'nation_count' => count($nationData),
                'resource_count' => count($resourcesData),
                'military_count' => count($militaryData),
                'city_count' => count($citiesData),
                'api_ms' => $apiMilliseconds,
                'transform_ms' => $transformMilliseconds,
                'database_ms' => $databaseMilliseconds,
                'total_ms' => $this->elapsedMilliseconds($jobStartedAt),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

        } catch (Throwable $exception) {
            Log::error('Failed to fetch nations batch', [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'stage' => $stage,
                'elapsed_ms' => $this->elapsedMilliseconds($jobStartedAt),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    /**
     * Preserve the defaults and bounds previously applied by GraphQL model hydration.
     *
     * @param  array<string, mixed>  $nation
     * @return array<string, mixed>
     */
    private function normalizeNationPayload(array $nation): array
    {
        foreach (self::ZERO_DEFAULT_COLUMNS as $column) {
            if (! isset($nation[$column])) {
                $nation[$column] = 0;
            }
        }

        if (isset($nation['vacation_mode_turns']) && (int) $nation['vacation_mode_turns'] > 65000) {
            $nation['vacation_mode_turns'] = 65000;
        }

        return $nation;
    }

    /**
     * Extract nation core data from a nation payload.
     *
     * @return array<string, mixed>
     */
    private function extractNationData(array $nation): array
    {
        $data = $this->mapValues($nation, self::NATION_COLUMNS, 'nation');
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;
        $data['deleted_at'] = null;

        return $data;
    }

    /**
     * Extract resource data from a nation payload.
     *
     * @return array<string, mixed>|null
     */
    private function extractResourceData(array $nation): ?array
    {
        if (! array_key_exists('money', $nation) || $nation['money'] === null) {
            return null;
        }
        $data = $this->mapValues($nation, self::RESOURCE_COLUMNS, 'resources');
        if ($data['credits'] === null) {
            $data['credits'] = 0;
        }
        $data['nation_id'] = $nation['id'];
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;
        $data['deleted_at'] = null;

        return $data;
    }

    /**
     * Extract military data from a nation payload.
     *
     * @return array<string, mixed>
     */
    private function extractMilitaryData(array $nation): array
    {
        $data = $this->mapValues($nation, self::MILITARY_COLUMNS, 'military');
        $data['nation_id'] = $nation['id'];
        $data['updated_at'] = $this->syncTimestampString;
        $data['created_at'] = $this->syncTimestampString;
        $data['deleted_at'] = null;

        return $data;
    }

    /**
     * Extract cities data from a nation payload.
     *
     * @param  array<string, mixed>  $nation
     * @param  list<array<string, mixed>>  $cities
     */
    private function appendCitiesData(array $nation, array &$cities): void
    {
        if (empty($nation['cities'])) {
            return;
        }

        foreach ($nation['cities'] as $city) {
            $cityArray = is_array($city) ? $city : (array) $city;
            $cityData = $this->mapValues($cityArray, self::CITY_COLUMNS, 'city');
            $cityData = City::normalizeApiPayload($cityData);
            $cityData['nation_id'] = $nation['id'];
            $cityData['updated_at'] = $this->syncTimestampString;
            $cityData['created_at'] = $this->syncTimestampString;
            $cityData['deleted_at'] = null;
            $cities[] = $cityData;
        }
    }

    /**
     * Perform bulk upserts without Telescope expanding every bound value into the SQL string.
     *
     * A city chunk contains tens of thousands of bindings. Telescope's query watcher replaces
     * those placeholders one at a time, which is substantially slower than the MySQL statement.
     * Recording resumes before the job finishes, so the job itself remains observable.
     */
    private function bulkUpsert(array $nationData, array $resourcesData, array $militaryData, array $citiesData): void
    {
        Telescope::withoutRecording(function () use ($nationData, $resourcesData, $militaryData, $citiesData): void {
            DB::transaction(function () use ($nationData, $resourcesData, $militaryData, $citiesData) {
                if (! empty($nationData)) {
                    foreach (array_chunk($nationData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        // Query builder upserts avoid the overhead of hydrating Eloquent models per row.
                        DB::table(self::TABLE_NATIONS)->upsert($chunk, ['id'], self::NATION_UPDATE_COLUMNS);
                    }
                }
                if (! empty($resourcesData)) {
                    foreach (array_chunk($resourcesData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        DB::table(self::TABLE_NATION_RESOURCES)->upsert($chunk, ['nation_id'], self::RESOURCE_UPDATE_COLUMNS);
                    }
                }
                if (! empty($militaryData)) {
                    foreach (array_chunk($militaryData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        DB::table(self::TABLE_NATION_MILITARY)->upsert($chunk, ['nation_id'], self::MILITARY_UPDATE_COLUMNS);
                    }
                }
                if (! empty($citiesData)) {
                    foreach (array_chunk($citiesData, self::UPSERT_CHUNK_SIZE) as $chunk) {
                        DB::table(self::TABLE_CITIES)->upsert($chunk, ['id'], self::CITY_UPDATE_COLUMNS);
                    }
                }
            }, 3);
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
    private function mapValues(array $source, array $columns, string $templateKey): array
    {
        static $templates = [];

        if (! isset($templates[$templateKey])) {
            $templates[$templateKey] = array_fill_keys($columns, null);
        }

        $template = $templates[$templateKey];

        return array_replace($template, array_intersect_key($source, $template));
    }
}
