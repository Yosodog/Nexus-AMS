<?php

namespace App\Jobs;

use App\Models\Cities;
use App\Models\Nations;
use App\Services\NationQueryService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncNationsJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 99999999;

    public int $page;
    public int $perPage;

    /**
     * Create a new job instance.
     */
    public function __construct(int $page, int $perPage)
    {
        $this->page = $page;
        $this->perPage = $perPage;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Fetch nations from the API
            $nations = NationQueryService::getMultipleNations(
                ["page" => $this->page],
                $this->perPage,
                true,
                handlePagination: false
            );

            $nationData = [];
            $resourcesData = [];
            $militaryData = [];
            $citiesData = [];

            $nationKeys = [
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

            $resourcesKeys = [
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
                'credits'
            ];

            $militaryKeys = [
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

            $cityKeys = [
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

            // Precompute the flipped arrays to avoid repeated array_flip() calls
            $nationKeysFlipped = array_flip($nationKeys);
            $resourcesKeysFlipped = array_flip($resourcesKeys);
            $militaryKeysFlipped = array_flip($militaryKeys);
            $cityKeysFlipped = array_flip($cityKeys);

            foreach ($nations as $nation) {
                // Prepare nation data array using native functions
                $data = array_intersect_key((array)$nation, $nationKeysFlipped);
                $nationData[] = $data;

                // Prepare resources data if available
                if (!is_null($nation->money)) {
                    $resourcesData[] = array_merge(
                        array_intersect_key((array)$nation, $resourcesKeysFlipped),
                        ['nation_id' => $nation->id]
                    );
                }

                // Prepare military data
                $military = array_merge(
                    array_intersect_key((array)$nation, $militaryKeysFlipped),
                    ['nation_id' => $nation->id]
                );
                if (!empty($military)) {
                    $militaryData[] = $military;
                }

                // Prepare cities data if any
                if (!empty($nation->cities)) {
                    foreach ($nation->cities as $city) {
                        $citiesData[] = array_intersect_key((array)$city, $cityKeysFlipped);
                    }
                }
            }

            // Perform bulk upserts inside a transaction for improved performance
            DB::transaction(function () use ($nationData, $resourcesData, $militaryData, $citiesData) {
                if (!empty($nationData)) {
                    Nations::upsert(
                        $nationData,
                        ['id'], // Unique key for nations
                        array_keys(reset($nationData)) // Columns to update; adjust as needed
                    );
                }

                if (!empty($resourcesData)) {
                    \App\Models\NationResources::upsert(
                        $resourcesData,
                        ['nation_id'],
                        array_keys(reset($resourcesData))
                    );
                }

                if (!empty($militaryData)) {
                    \App\Models\NationMilitary::upsert(
                        $militaryData,
                        ['nation_id'],
                        array_keys(reset($militaryData))
                    );
                }

                if (!empty($citiesData)) {
                    foreach (array_chunk($citiesData, 500) as $chunk) {
                        Cities::upsert(
                            $chunk,
                            ['id'],
                            array_keys(reset($chunk))
                        );
                    }
                }
            });

            // Clean up memory after processing
            unset($nations, $nationData, $resourcesData, $militaryData, $citiesData);
            gc_collect_cycles();
        } catch (Exception $e) {
            Log::error("Failed to fetch nations: " . $e->getMessage());
        }
    }
}