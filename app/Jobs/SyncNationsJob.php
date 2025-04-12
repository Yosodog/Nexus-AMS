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
use App\Models\NationMilitary;
use App\Models\NationResources;
use App\Models\Nations;
use App\Services\NationQueryService;
use App\Services\PWHelperService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Job to synchronize nations data from external API and persist it to the database
class SyncNationsJob implements ShouldQueue
{
    use Queueable;

    // Set a very high timeout to allow the job to complete even if it takes a long time
    public $timeout = 99999999;

    // Pagination parameters: current page and number of items per page
    public int $page;
    public int $perPage;

    /**
     * Create a new job instance.
     *
     * @param int $page The current page number to fetch from the API
     * @param int $perPage The number of nations to fetch per page
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Execute the job.
     *
     * This method handles the full process of fetching, transforming, and upserting nation data into the database.
     * It wraps the process in a try-catch block to log any errors that might occur during execution.
     */
    public function handle(): void
    {
        try {
            // Fetch nations from the API using the NationQueryService with pagination parameters
            $nations = NationQueryService::getMultipleNations(
                ["page" => $this->page],
                $this->perPage,
                true,
                handlePagination: false
            );

            // Initialize arrays to hold transformed data for nations, resources, military, and cities
            $nationData = [];
            $resourcesData = [];
            $militaryData = [];
            $citiesData = [];

            // Process each nation and extract necessary data
            foreach ($nations as $nation) {
                // Extract nation core data
                $nationData[] = $this->extractNationData($nation);

                // Extract resource data if available
                $resourceData = $this->extractResourceData($nation);
                if (!is_null($resourceData)) {
                    $resourcesData[] = $resourceData;
                }

                // Extract military data
                $militaryDataArray = $this->extractMilitaryData($nation);
                if (!empty($militaryDataArray)) {
                    $militaryData[] = $militaryDataArray;
                }

                // Extract cities data
                $citiesDataArray = $this->extractCitiesData($nation);
                if (!empty($citiesDataArray)) {
                    $citiesData = array_merge($citiesData, $citiesDataArray);
                }
            }

            // Perform bulk upsert operations in a single transaction for improved performance and atomicity
            $this->bulkUpsert($nationData, $resourcesData, $militaryData, $citiesData);

            // Clean up variables and force garbage collection to free memory
            unset($nations, $nationData, $resourcesData, $militaryData, $citiesData);
            gc_collect_cycles();
        } catch (Exception $e) {
            // Log any exceptions encountered during the synchronization process
            Log::error("Failed to fetch nations: " . $e->getMessage());
        }
    }

    /**
     * Extract nation core data from a nation object.
     *
     * @param mixed $nation
     * @return array
     */
    private function extractNationData($nation): array
    {
        $keys = $this->getNationKeys();
        $keysFlipped = array_flip($keys);
        return array_intersect_key((array)$nation, $keysFlipped);
    }

    /**
     * Get the list of keys for nation data extraction.
     *
     * @return array
     */
    private function getNationKeys(): array
    {
        return [
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
            'total_infrastructure_lost'
        ];
    }

    /**
     * Extract resource data from a nation object.
     *
     * @param mixed $nation
     * @return array|null Returns null if no monetary data is available.
     */
    private function extractResourceData($nation): ?array
    {
        if (is_null($nation->money)) {
            return null;
        }
        $keys = $this->getResourceKeys();
        $keysFlipped = array_flip($keys);
        $data = array_intersect_key((array)$nation, $keysFlipped);
        return array_merge($data, ['nation_id' => $nation->id]);
    }

    /**
     * Get the list of keys for resource data extraction.
     *
     * @return array
     */
    private function getResourceKeys(): array
    {
        return PWHelperService::resources(includeCredits: true);
    }

    /**
     * Extract military data from a nation object.
     *
     * @param mixed $nation
     * @return array
     */
    private function extractMilitaryData($nation): array
    {
        $keys = $this->getMilitaryKeys();
        $keysFlipped = array_flip($keys);
        $data = array_intersect_key((array)$nation, $keysFlipped);
        return array_merge($data, ['nation_id' => $nation->id]);
    }

    /**
     * Get the list of keys for military data extraction.
     *
     * @return array
     */
    private function getMilitaryKeys(): array
    {
        return [
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
            'spy_attacks'
        ];
    }

    /**
     * Extract cities data from a nation object.
     *
     * @param mixed $nation
     * @return array
     */
    private function extractCitiesData($nation): array
    {
        $cities = [];
        if (empty($nation->cities)) {
            return $cities;
        }
        $keys = $this->getCityKeys();
        $keysFlipped = array_flip($keys);
        foreach ($nation->cities as $city) {
            $cities[] = array_intersect_key((array)$city, $keysFlipped);
        }
        return $cities;
    }

    /**
     * Get the list of keys for city data extraction.
     *
     * @return array
     */
    private function getCityKeys(): array
    {
        return [
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
            'drydock'
        ];
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
                Nations::upsert(
                    $nationData,
                    ['id'],
                    array_keys(reset($nationData))
                );
            }
            if (!empty($resourcesData)) {
                NationResources::upsert(
                    $resourcesData,
                    ['nation_id'],
                    array_keys(reset($resourcesData))
                );
            }
            if (!empty($militaryData)) {
                NationMilitary::upsert(
                    $militaryData,
                    ['nation_id'],
                    array_keys(reset($militaryData))
                );
            }
            if (!empty($citiesData)) {
                foreach (array_chunk($citiesData, 500) as $chunk) {
                    City::upsert(
                        $chunk,
                        ['id'],
                        array_keys(reset($chunk))
                    );
                }
            }
        });
    }
}