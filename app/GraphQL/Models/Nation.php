<?php

namespace App\GraphQL\Models;

class Nation
{
    public int $id;
    public int $alliance_id;
    public string $alliance_position;
    public int $alliance_position_id;
//    public AlliancePosition $alliance_position_info;
//    public Alliance $alliance;
    public string $nation_name;
    public string $leader_name;
    public string $continent;
//    public WarPolicy $war_policy;
    public int $war_policy_turns;
//    public DomesticPolicy $domestic_policy;
    public int $domestic_policy_turns;
    public string $color;
    public int $num_cities;
    public Cities $cities;
    public float $score;
    public float $update_tz;
    public int $population;
    public string $flag;
    public int $vacation_mode_turns;
    public int $beige_turns;
    public bool $espionage_available;
//    public DateTime $last_active;
//    public DateTime $date;
    public int $soldiers;
    public int $tanks;
    public int $aircraft;
    public int $ships;
    public int $missiles;
    public int $nukes;
    public int $spies;
    public int $soldiers_today;
    public int $tanks_today;
    public int $aircraft_today;
    public int $ships_today;
    public int $missiles_today;
    public int $nukes_today;
    public int $spies_today;
    public string $discord;
    public string $discord_id;
//    public array $treasures; // Array of Treasure objects
//    public array $wars; // Array of War objects
//    public array $bankrecs; // Array of Bankrec objects
//    public array $trades; // Array of Trade objects
//    public array $taxrecs; // Array of Bankrec objects
//    public array $bounties; // Array of Bounty objects
    public int $turns_since_last_city;
    public int $turns_since_last_project;
    public float $money;
    public float $coal;
    public float $oil;
    public float $uranium;
    public float $iron;
    public float $bauxite;
    public float $lead;
    public float $gasoline;
    public float $munitions;
    public float $steel;
    public float $aluminum;
    public float $food;
    public int $credits;
    public int $projects;
    public string $project_bits;
    public bool $iron_works;
    public bool $bauxite_works;
    public bool $arms_stockpile;
    public bool $emergency_gasoline_reserve;
    public bool $mass_irrigation;
    public bool $international_trade_center;
    public bool $missile_launch_pad;
    public bool $nuclear_research_facility;
    public bool $iron_dome;
    public bool $vital_defense_system;
    public bool $central_intelligence_agency;
    public bool $center_for_civil_engineering;
    public bool $propaganda_bureau;
    public bool $uranium_enrichment_program;
    public bool $urban_planning;
    public bool $advanced_urban_planning;
    public bool $space_program;
    public bool $spy_satellite;
    public bool $moon_landing;
    public bool $pirate_economy;
    public bool $recycling_initiative;
    public bool $telecommunications_satellite;
    public bool $green_technologies;
    public bool $arable_land_agency;
    public bool $clinical_research_center;
    public bool $specialized_police_training_program;
    public bool $advanced_engineering_corps;
    public bool $government_support_agency;
    public bool $research_and_development_center;
    public bool $metropolitan_planning;
    public bool $military_salvage;
    public bool $fallout_shelter;
    public bool $activity_center;
    public bool $bureau_of_domestic_affairs;
    public bool $advanced_pirate_economy;
    public bool $mars_landing;
    public bool $surveillance_network;
    public bool $guiding_satellite;
    public bool $nuclear_launch_facility;
//    public DateTime $moon_landing_date;
//    public DateTime $mars_landing_date;
    public int $wars_won;
    public int $wars_lost;
    public int $tax_id;
    public int $alliance_seniority;
//    public BBTeam $baseball_team;
    public float $gross_national_income;
    public float $gross_domestic_product;
    public int $soldier_casualties;
    public int $soldier_kills;
    public int $tank_casualties;
    public int $tank_kills;
    public int $aircraft_casualties;
    public int $aircraft_kills;
    public int $ship_casualties;
    public int $ship_kills;
    public int $missile_casualties;
    public int $missile_kills;
    public int $nuke_casualties;
    public int $nuke_kills;
    public int $spy_casualties;
    public int $spy_kills;
    public int $spy_attacks;
    public float $money_looted;
    public float $total_infrastructure_destroyed;
    public float $total_infrastructure_lost;
    public bool $vip;
    public int $commendations;
    public int $denouncements;
    public int $offensive_wars_count;
    public int $defensive_wars_count;
//    public EconomicPolicy $economic_policy;
//    public SocialPolicy $social_policy;
//    public GovernmentType $government_type;
    public int $credits_redeemed_this_month;
//    public DateTime $alliance_join_date;
//    public array $awards; // Array of Award objects
//    public array $bulletins; // Array of Bulletin objects
//    public array $bulletin_replies; // Array of BulletinReply objects

    /**
     * @param \stdClass $json
     * @return void
     */
    public function buildWithJSON(\stdClass $json)
    {
        $this->id = (int) $json->id;
        $this->alliance_id = (int) $json->alliance_id;
        $this->alliance_position = (string) $json->alliance_position;
        $this->alliance_position_id = (int) $json->alliance_position_id;
        $this->nation_name = (string) $json->nation_name;
        $this->leader_name = (string) $json->leader_name;
        $this->continent = (string) $json->continent;
        $this->war_policy_turns = (int) $json->war_policy_turns;
        $this->domestic_policy_turns = (int) $json->domestic_policy_turns;
        $this->color = (string) $json->color;
        $this->num_cities = (int) $json->num_cities;

        if (isset($json->cities))
        {
            $this->cities = new Cities([]);

            foreach ($json->cities as $city)
            {
                $cityModel = new City();
                $cityModel->buildWithJSON((object)$city);
                $this->cities->add($cityModel);
            }
        }

        $this->score = (float) $json->score;
        $this->update_tz = (float) $json->update_tz;
        $this->population = (int) $json->population;
        $this->flag = (string) $json->flag;
        $this->vacation_mode_turns = (int) $json->vacation_mode_turns;
        $this->beige_turns = (int) $json->beige_turns;
        $this->espionage_available = (bool) $json->espionage_available;
        $this->soldiers = (int) $json->soldiers;
        $this->tanks = (int) $json->tanks;
        $this->aircraft = (int) $json->aircraft;
        $this->ships = (int) $json->ships;
        $this->missiles = (int) $json->missiles;
        $this->nukes = (int) $json->nukes;
        $this->spies = (int) $json->spies;
        $this->soldiers_today = (int) $json->soldiers_today;
        $this->tanks_today = (int) $json->tanks_today;
        $this->aircraft_today = (int) $json->aircraft_today;
        $this->ships_today = (int) $json->ships_today;
        $this->missiles_today = (int) $json->missiles_today;
        $this->nukes_today = (int) $json->nukes_today;
        $this->spies_today = (int) $json->spies_today;
        $this->discord = (string) $json->discord;
        $this->discord_id = (string) $json->discord_id;
        $this->turns_since_last_city = (int) $json->turns_since_last_city;
        $this->turns_since_last_project = (int) $json->turns_since_last_project;
        $this->money = (float) $json->money;
        $this->coal = (float) $json->coal;
        $this->oil = (float) $json->oil;
        $this->uranium = (float) $json->uranium;
        $this->iron = (float) $json->iron;
        $this->bauxite = (float) $json->bauxite;
        $this->lead = (float) $json->lead;
        $this->gasoline = (float) $json->gasoline;
        $this->munitions = (float) $json->munitions;
        $this->steel = (float) $json->steel;
        $this->aluminum = (float) $json->aluminum;
        $this->food = (float) $json->food;
        $this->credits = (int) $json->credits;
        $this->projects = (int) $json->projects;
        $this->project_bits = (string) $json->project_bits;
        $this->iron_works = (bool) $json->iron_works;
        $this->bauxite_works = (bool) $json->bauxite_works;
        $this->arms_stockpile = (bool) $json->arms_stockpile;
        $this->emergency_gasoline_reserve = (bool) $json->emergency_gasoline_reserve;
        $this->mass_irrigation = (bool) $json->mass_irrigation;
        $this->international_trade_center = (bool) $json->international_trade_center;
        $this->missile_launch_pad = (bool) $json->missile_launch_pad;
        $this->nuclear_research_facility = (bool) $json->nuclear_research_facility;
        $this->iron_dome = (bool) $json->iron_dome;
        $this->vital_defense_system = (bool) $json->vital_defense_system;
        $this->central_intelligence_agency = (bool) $json->central_intelligence_agency;
        $this->center_for_civil_engineering = (bool) $json->center_for_civil_engineering;
        $this->propaganda_bureau = (bool) $json->propaganda_bureau;
        $this->uranium_enrichment_program = (bool) $json->uranium_enrichment_program;
        $this->urban_planning = (bool) $json->urban_planning;
        $this->advanced_urban_planning = (bool) $json->advanced_urban_planning;
        $this->space_program = (bool) $json->space_program;
        $this->spy_satellite = (bool) $json->spy_satellite;
        $this->moon_landing = (bool) $json->moon_landing;
        $this->pirate_economy = (bool) $json->pirate_economy;
        $this->recycling_initiative = (bool) $json->recycling_initiative;
        $this->telecommunications_satellite = (bool) $json->telecommunications_satellite;
        $this->green_technologies = (bool) $json->green_technologies;
        $this->arable_land_agency = (bool) $json->arable_land_agency;
        $this->clinical_research_center = (bool) $json->clinical_research_center;
        $this->specialized_police_training_program = (bool) $json->specialized_police_training_program;
        $this->advanced_engineering_corps = (bool) $json->advanced_engineering_corps;
        $this->government_support_agency = (bool) $json->government_support_agency;
        $this->research_and_development_center = (bool) $json->research_and_development_center;
        $this->metropolitan_planning = (bool) $json->metropolitan_planning;
        $this->military_salvage = (bool) $json->military_salvage;
        $this->fallout_shelter = (bool) $json->fallout_shelter;
        $this->activity_center = (bool) $json->activity_center;
        $this->bureau_of_domestic_affairs = (bool) $json->bureau_of_domestic_affairs;
        $this->advanced_pirate_economy = (bool) $json->advanced_pirate_economy;
        $this->mars_landing = (bool) $json->mars_landing;
        $this->surveillance_network = (bool) $json->surveillance_network;
        $this->guiding_satellite = (bool) $json->guiding_satellite;
        $this->nuclear_launch_facility = (bool) $json->nuclear_launch_facility;
        $this->wars_won = (int) $json->wars_won;
        $this->wars_lost = (int) $json->wars_lost;
        $this->tax_id = (int) $json->tax_id;
        $this->alliance_seniority = (int) $json->alliance_seniority;
        $this->gross_national_income = (float) $json->gross_national_income;
        $this->gross_domestic_product = (float) $json->gross_domestic_product;
        $this->soldier_casualties = (int) $json->soldier_casualties;
        $this->soldier_kills = (int) $json->soldier_kills;
        $this->tank_casualties = (int) $json->tank_casualties;
        $this->tank_kills = (int) $json->tank_kills;
        $this->aircraft_casualties = (int) $json->aircraft_casualties;
        $this->aircraft_kills = (int) $json->aircraft_kills;
        $this->ship_casualties = (int) $json->ship_casualties;
        $this->ship_kills = (int) $json->ship_kills;
        $this->missile_casualties = (int) $json->missile_casualties;
        $this->missile_kills = (int) $json->missile_kills;
        $this->nuke_casualties = (int) $json->nuke_casualties;
        $this->nuke_kills = (int) $json->nuke_kills;
        $this->spy_casualties = (int) $json->spy_casualties;
        $this->spy_kills = (int) $json->spy_kills;
        $this->spy_attacks = (int) $json->spy_attacks;
        $this->money_looted = (float) $json->money_looted;
        $this->total_infrastructure_destroyed = (float) $json->total_infrastructure_destroyed;
        $this->total_infrastructure_lost = (float) $json->total_infrastructure_lost;
        $this->vip = (bool) $json->vip;
        $this->commendations = (int) $json->commendations;
        $this->denouncements = (int) $json->denouncements;
        $this->offensive_wars_count = (int) $json->offensive_wars_count;
        $this->defensive_wars_count = (int) $json->defensive_wars_count;
        $this->credits_redeemed_this_month = (int) $json->credits_redeemed_this_month;
    }
}
