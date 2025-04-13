<?php

namespace App\GraphQL\Models;

use Carbon\Carbon;
use stdClass;

class Nation
{
    public ?int $id = null;
    public ?int $alliance_id = null;
    public ?string $alliance_position = null;
    public ?int $alliance_position_id = null;
    public ?string $nation_name = null;
    public ?string $leader_name = null;
    public ?string $continent = null;
    public ?int $war_policy_turns = null;
    public ?int $domestic_policy_turns = null;
    public ?string $color = null;
    public ?int $num_cities = null;
    public ?Cities $cities = null;
    public ?Wars $wars = null;
    public ?float $score = null;
    public ?float $update_tz = null;
    public ?int $population = null;
    public ?string $flag = null;
    public ?int $vacation_mode_turns = null;
    public ?int $beige_turns = null;
    public ?bool $espionage_available = null;
    public ?int $soldiers = null;
    public ?int $tanks = null;
    public ?int $aircraft = null;
    public ?int $ships = null;
    public ?int $missiles = null;
    public ?int $nukes = null;
    public ?int $spies = null;
    public ?int $soldiers_today = null;
    public ?int $tanks_today = null;
    public ?int $aircraft_today = null;
    public ?int $ships_today = null;
    public ?int $missiles_today = null;
    public ?int $nukes_today = null;
    public ?int $spies_today = null;
    public ?string $discord = null;
    public ?string $discord_id = null;
    public ?int $turns_since_last_city = null;
    public ?int $turns_since_last_project = null;
    public ?float $money = null;
    public ?float $coal = null;
    public ?float $oil = null;
    public ?float $uranium = null;
    public ?float $iron = null;
    public ?float $bauxite = null;
    public ?float $lead = null;
    public ?float $gasoline = null;
    public ?float $munitions = null;
    public ?float $steel = null;
    public ?float $aluminum = null;
    public ?float $food = null;
    public ?int $credits = null;
    public ?int $projects = null;
    public ?string $project_bits = null;

    // Projects
    public ?bool $iron_works = null;
    public ?bool $bauxite_works = null;
    public ?bool $arms_stockpile = null;
    public ?bool $emergency_gasoline_reserve = null;
    public ?bool $mass_irrigation = null;
    public ?bool $international_trade_center = null;
    public ?bool $missile_launch_pad = null;
    public ?bool $nuclear_research_facility = null;
    public ?bool $iron_dome = null;
    public ?bool $vital_defense_system = null;
    public ?bool $central_intelligence_agency = null;
    public ?bool $center_for_civil_engineering = null;
    public ?bool $propaganda_bureau = null;
    public ?bool $uranium_enrichment_program = null;
    public ?bool $urban_planning = null;
    public ?bool $advanced_urban_planning = null;
    public ?bool $space_program = null;
    public ?bool $spy_satellite = null;
    public ?bool $moon_landing = null;
    public ?bool $pirate_economy = null;
    public ?bool $recycling_initiative = null;
    public ?bool $telecommunications_satellite = null;
    public ?bool $green_technologies = null;
    public ?bool $arable_land_agency = null;
    public ?bool $clinical_research_center = null;
    public ?bool $specialized_police_training_program = null;
    public ?bool $advanced_engineering_corps = null;
    public ?bool $government_support_agency = null;
    public ?bool $research_and_development_center = null;
    public ?bool $metropolitan_planning = null;
    public ?bool $military_salvage = null;
    public ?bool $fallout_shelter = null;
    public ?bool $activity_center = null;
    public ?bool $bureau_of_domestic_affairs = null;
    public ?bool $advanced_pirate_economy = null;
    public ?bool $mars_landing = null;
    public ?bool $surveillance_network = null;
    public ?bool $guiding_satellite = null;
    public ?bool $nuclear_launch_facility = null;

    // War Statistics
    public ?int $wars_won = null;
    public ?int $wars_lost = null;
    public ?int $tax_id = null;
    public ?int $alliance_seniority = null;
    public ?float $gross_national_income = null;
    public ?float $gross_domestic_product = null;

    // Combat Casualties
    public ?int $soldier_casualties = null;
    public ?int $soldier_kills = null;
    public ?int $tank_casualties = null;
    public ?int $tank_kills = null;
    public ?int $aircraft_casualties = null;
    public ?int $aircraft_kills = null;
    public ?int $ship_casualties = null;
    public ?int $ship_kills = null;
    public ?int $missile_casualties = null;
    public ?int $missile_kills = null;
    public ?int $nuke_casualties = null;
    public ?int $nuke_kills = null;
    public ?int $spy_casualties = null;
    public ?int $spy_kills = null;
    public ?int $spy_attacks = null;

    // Economic Stats
    public ?float $money_looted = null;
    public ?float $total_infrastructure_destroyed = null;
    public ?float $total_infrastructure_lost = null;

    // Miscellaneous
    public ?bool $vip = null;
    public ?int $commendations = null;
    public ?int $denouncements = null;
    public ?int $offensive_wars_count = null;
    public ?int $defensive_wars_count = null;
    public ?int $credits_redeemed_this_month = null;
    public ?string $last_active = null;

    /**
     * I hate the function. Look away now.
     *
     * @param stdClass $json
     * @return void
     */
    public function buildWithJSON(stdClass $json)
    {
        $this->id = isset($json->id) ? (int)$json->id : null;
        $this->alliance_id = isset($json->alliance_id) ? (int)$json->alliance_id : null;
        $this->alliance_position = isset($json->alliance_position) ? (string)$json->alliance_position : null;
        $this->alliance_position_id = isset($json->alliance_position_id) ? (int)$json->alliance_position_id : null;
        $this->nation_name = isset($json->nation_name) ? (string)$json->nation_name : null;
        $this->leader_name = isset($json->leader_name) ? (string)$json->leader_name : null;
        $this->continent = isset($json->continent) ? (string)$json->continent : null;
        $this->war_policy_turns = isset($json->war_policy_turns) ? (int)$json->war_policy_turns : null;
        $this->domestic_policy_turns = isset($json->domestic_policy_turns) ? (int)$json->domestic_policy_turns : null;
        $this->color = isset($json->color) ? (string)$json->color : null;
        $this->num_cities = isset($json->num_cities) ? (int)$json->num_cities : null;
        $this->alliance_seniority = isset($json->alliance_seniority) ? (int)$json->alliance_seniority : null;
        $this->vip = isset($json->vip) ? (int)$json->vip : 0;
        $this->commendations = isset($json->commendations) ? (int)$json->commendations : 0;
        $this->denouncements = isset($json->denouncements) ? (int)$json->denouncements : 0;
        $this->offensive_wars_count = isset($json->offensive_wars_count) ? (int)$json->offensive_wars_count : null;
        $this->defensive_wars_count = isset($json->defensive_wars_count) ? (int)$json->defensive_wars_count : null;

        if (isset($json->cities) && is_array($json->cities)) {
            $this->cities = new Cities([]);
            foreach ($json->cities as $city) {
                $cityModel = new City();
                $cityModel->buildWithJSON((object)$city);
                $this->cities->add($cityModel);
            }
        }

        if (isset($json->wars) && is_array($json->wars)) {
            $this->wars = new Wars([]);
            foreach ($json->wars as $war) {
                $warModel = new War();
                $warModel->buildWithJSON((object)$war);
                $this->wars->add($warModel);
            }
        }

        $this->score = isset($json->score) ? (float)$json->score : null;
        $this->update_tz = isset($json->update_tz) ? (float)$json->update_tz : null;
        $this->population = isset($json->population) ? (int)$json->population : null;
        $this->flag = isset($json->flag) ? (string)$json->flag : null;
        $this->vacation_mode_turns = isset($json->vacation_mode_turns) ? (int)$json->vacation_mode_turns : null;
        if ($this->vacation_mode_turns > 65000) {
            $this->vacation_mode_turns = 65000; // Field is unsigned small int, and I don't want to change that because wtf
        }
        $this->beige_turns = isset($json->beige_turns) ? (int)$json->beige_turns : null;
        $this->espionage_available = isset($json->espionage_available) ? (bool)$json->espionage_available : null;

        // Military attributes
        $this->soldiers = isset($json->soldiers) ? (int)$json->soldiers : null;
        $this->tanks = isset($json->tanks) ? (int)$json->tanks : null;
        $this->aircraft = isset($json->aircraft) ? (int)$json->aircraft : null;
        $this->ships = isset($json->ships) ? (int)$json->ships : null;
        $this->missiles = isset($json->missiles) ? (int)$json->missiles : null;
        $this->nukes = isset($json->nukes) ? (int)$json->nukes : null;
        $this->spies = isset($json->spies) ? (int)$json->spies : null;

        // Daily unit counts (For some reason it's null in the API???)
        $this->soldiers_today = isset($json->soldiers_today) ? (int)$json->soldiers_today : 0;
        $this->tanks_today = isset($json->tanks_today) ? (int)$json->tanks_today : 0;
        $this->aircraft_today = isset($json->aircraft_today) ? (int)$json->aircraft_today : 0;
        $this->ships_today = isset($json->ships_today) ? (int)$json->ships_today : 0;
        $this->missiles_today = isset($json->missiles_today) ? (int)$json->missiles_today : 0;
        $this->nukes_today = isset($json->nukes_today) ? (int)$json->nukes_today : 0;
        $this->spies_today = isset($json->spies_today) ? (int)$json->spies_today : 0;

        $this->discord = isset($json->discord) ? (string)$json->discord : null;
        $this->discord_id = isset($json->discord_id) ? (string)$json->discord_id : null;
        $this->turns_since_last_city = isset($json->turns_since_last_city) ? (int)$json->turns_since_last_city : null;
        $this->turns_since_last_project = isset($json->turns_since_last_project) ? (int)$json->turns_since_last_project : null;

        // Resources
        $this->money = isset($json->money) ? (float)$json->money : null;
        $this->coal = isset($json->coal) ? (float)$json->coal : null;
        $this->oil = isset($json->oil) ? (float)$json->oil : null;
        $this->uranium = isset($json->uranium) ? (float)$json->uranium : null;
        $this->iron = isset($json->iron) ? (float)$json->iron : null;
        $this->bauxite = isset($json->bauxite) ? (float)$json->bauxite : null;
        $this->lead = isset($json->lead) ? (float)$json->lead : null;
        $this->gasoline = isset($json->gasoline) ? (float)$json->gasoline : null;
        $this->munitions = isset($json->munitions) ? (float)$json->munitions : null;
        $this->steel = isset($json->steel) ? (float)$json->steel : null;
        $this->aluminum = isset($json->aluminum) ? (float)$json->aluminum : null;
        $this->food = isset($json->food) ? (float)$json->food : null;
        $this->credits = isset($json->credits) ? (int)$json->credits : null;

        // Projects
        $this->projects = isset($json->projects) ? (int)$json->projects : null;
        $this->project_bits = isset($json->project_bits) ? (string)$json->project_bits : null;
        $this->iron_works = isset($json->iron_works) ? (bool)$json->iron_works : null;
        $this->bauxite_works = isset($json->bauxite_works) ? (bool)$json->bauxite_works : null;
        $this->arms_stockpile = isset($json->arms_stockpile) ? (bool)$json->arms_stockpile : null;
        $this->emergency_gasoline_reserve = isset($json->emergency_gasoline_reserve) ? (bool)$json->emergency_gasoline_reserve : null;
        $this->mass_irrigation = isset($json->mass_irrigation) ? (bool)$json->mass_irrigation : null;
        $this->international_trade_center = isset($json->international_trade_center) ? (bool)$json->international_trade_center : null;
        $this->missile_launch_pad = isset($json->missile_launch_pad) ? (bool)$json->missile_launch_pad : null;
        $this->nuclear_research_facility = isset($json->nuclear_research_facility) ? (bool)$json->nuclear_research_facility : null;
        $this->iron_dome = isset($json->iron_dome) ? (bool)$json->iron_dome : null;
        $this->vital_defense_system = isset($json->vital_defense_system) ? (bool)$json->vital_defense_system : null;
        $this->central_intelligence_agency = isset($json->central_intelligence_agency) ? (bool)$json->central_intelligence_agency : null;
        $this->center_for_civil_engineering = isset($json->center_for_civil_engineering) ? (bool)$json->center_for_civil_engineering : null;
        $this->propaganda_bureau = isset($json->propaganda_bureau) ? (bool)$json->propaganda_bureau : null;
        $this->uranium_enrichment_program = isset($json->uranium_enrichment_program) ? (bool)$json->uranium_enrichment_program : null;
        $this->urban_planning = isset($json->urban_planning) ? (bool)$json->urban_planning : null;
        $this->advanced_urban_planning = isset($json->advanced_urban_planning) ? (bool)$json->advanced_urban_planning : null;
        $this->space_program = isset($json->space_program) ? (bool)$json->space_program : null;
        $this->spy_satellite = isset($json->spy_satellite) ? (bool)$json->spy_satellite : null;
        $this->moon_landing = isset($json->moon_landing) ? (bool)$json->moon_landing : null;
        $this->pirate_economy = isset($json->pirate_economy) ? (bool)$json->pirate_economy : null;
        $this->recycling_initiative = isset($json->recycling_initiative) ? (bool)$json->recycling_initiative : null;
        $this->telecommunications_satellite = isset($json->telecommunications_satellite) ? (bool)$json->telecommunications_satellite : null;
        $this->green_technologies = isset($json->green_technologies) ? (bool)$json->green_technologies : null;
        $this->arable_land_agency = isset($json->arable_land_agency) ? (bool)$json->arable_land_agency : null;
        $this->clinical_research_center = isset($json->clinical_research_center) ? (bool)$json->clinical_research_center : null;
        $this->specialized_police_training_program = isset($json->specialized_police_training_program) ? (bool)$json->specialized_police_training_program : null;
        $this->advanced_engineering_corps = isset($json->advanced_engineering_corps) ? (bool)$json->advanced_engineering_corps : null;
        $this->government_support_agency = isset($json->government_support_agency) ? (bool)$json->government_support_agency : null;
        $this->research_and_development_center = isset($json->research_and_development_center) ? (bool)$json->research_and_development_center : null;
        $this->metropolitan_planning = isset($json->metropolitan_planning) ? (bool)$json->metropolitan_planning : null;
        $this->military_salvage = isset($json->military_salvage) ? (bool)$json->military_salvage : null;
        $this->fallout_shelter = isset($json->fallout_shelter) ? (bool)$json->fallout_shelter : null;
        $this->activity_center = isset($json->activity_center) ? (bool)$json->activity_center : null;
        $this->bureau_of_domestic_affairs = isset($json->bureau_of_domestic_affairs) ? (bool)$json->bureau_of_domestic_affairs : null;
        $this->advanced_pirate_economy = isset($json->advanced_pirate_economy) ? (bool)$json->advanced_pirate_economy : null;
        $this->mars_landing = isset($json->mars_landing) ? (bool)$json->mars_landing : null;
        $this->surveillance_network = isset($json->surveillance_network) ? (bool)$json->surveillance_network : null;
        $this->guiding_satellite = isset($json->guiding_satellite) ? (bool)$json->guiding_satellite : null;
        $this->nuclear_launch_facility = isset($json->nuclear_launch_facility) ? (bool)$json->nuclear_launch_facility : null;

        // War Stats
        $this->wars_won = isset($json->wars_won) ? (int)$json->wars_won : null;
        $this->wars_lost = isset($json->wars_lost) ? (int)$json->wars_lost : null;

        // Economy
        $this->gross_national_income = isset($json->gross_national_income) ? (float)$json->gross_national_income : null;
        $this->gross_domestic_product = isset($json->gross_domestic_product) ? (float)$json->gross_domestic_product : null;

        // Combat Casualties
        $this->soldier_casualties = isset($json->soldier_casualties) ? (int)$json->soldier_casualties : 0;
        $this->soldier_kills = isset($json->soldier_kills) ? (int)$json->soldier_kills : 0;
        $this->tank_casualties = isset($json->tank_casualties) ? (int)$json->tank_casualties : 0;
        $this->tank_kills = isset($json->tank_kills) ? (int)$json->tank_kills : 0;
        $this->aircraft_casualties = isset($json->aircraft_casualties) ? (int)$json->aircraft_casualties : 0;
        $this->aircraft_kills = isset($json->aircraft_kills) ? (int)$json->aircraft_kills : 0;
        $this->ship_casualties = isset($json->ship_casualties) ? (int)$json->ship_casualties : 0;
        $this->ship_kills = isset($json->ship_kills) ? (int)$json->ship_kills : 0;
        $this->missile_casualties = isset($json->missile_casualties) ? (int)$json->missile_casualties : 0;
        $this->missile_kills = isset($json->missile_kills) ? (int)$json->missile_kills : 0;
        $this->nuke_casualties = isset($json->nuke_casualties) ? (int)$json->nuke_casualties : 0;
        $this->nuke_kills = isset($json->nuke_kills) ? (int)$json->nuke_kills : 0;
        $this->spy_casualties = isset($json->spy_casualties) ? (int)$json->spy_casualties : 0;
        $this->spy_kills = isset($json->spy_kills) ? (int)$json->spy_kills : 0;
        $this->spy_attacks = isset($json->spy_attacks) ? (int)$json->spy_attacks : 0;

        // Additional Stats
        $this->money_looted = isset($json->money_looted) ? (float)$json->money_looted : null;
        $this->total_infrastructure_destroyed = isset($json->total_infrastructure_destroyed) ? (float)$json->total_infrastructure_destroyed : null;
        $this->total_infrastructure_lost = isset($json->total_infrastructure_lost) ? (float)$json->total_infrastructure_lost : null;

        if (isset($json->last_active)) {
            $this->last_active = Carbon::create($json->last_active)->toDateTimeString();
        }
    }

    /**
     * @return bool
     */
    public function isApplicant(): bool
    {
        return $this->alliance_position == "APPLICANT";
    }
}
