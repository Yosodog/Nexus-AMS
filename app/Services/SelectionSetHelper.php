<?php

namespace App\Services;

class SelectionSetHelper
{
    /**
     * @return string[]
     */
    public static function nationSet(): array
    {
        return [
            'id',
            'alliance_id',
            'alliance_position',
            'alliance_position_id',
            // 'alliance_position_info', // AlliancePosition class
            // 'alliance', // Alliance class
            'nation_name',
            'leader_name',
            'continent',
            // 'war_policy', // WarPolicy class
            'war_policy_turns',
            // 'domestic_policy', // DomesticPolicy class
            'domestic_policy_turns',
            'color',
            'num_cities',
            //            'cities',
            'score',
            'update_tz',
            'population',
            'flag',
            'vacation_mode_turns',
            'beige_turns',
            'espionage_available',
            'last_active',
            'date',
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
            'discord',
            'discord_id',
            //            'treasures',
            //            'wars',
            //            'bankrecs',
            //            'trades',
            //            'taxrecs',
            //            'bounties',
            'turns_since_last_city',
            'turns_since_last_project',
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
            'projects',
            'project_bits',
            'iron_works',
            'bauxite_works',
            'arms_stockpile',
            'emergency_gasoline_reserve',
            'mass_irrigation',
            'international_trade_center',
            'missile_launch_pad',
            'nuclear_research_facility',
            'iron_dome',
            'vital_defense_system',
            'central_intelligence_agency',
            'center_for_civil_engineering',
            'propaganda_bureau',
            'uranium_enrichment_program',
            'urban_planning',
            'advanced_urban_planning',
            'space_program',
            'spy_satellite',
            'moon_landing',
            'pirate_economy',
            'recycling_initiative',
            'telecommunications_satellite',
            'green_technologies',
            'arable_land_agency',
            'clinical_research_center',
            'specialized_police_training_program',
            'advanced_engineering_corps',
            'government_support_agency',
            'research_and_development_center',
            'metropolitan_planning',
            'military_salvage',
            'fallout_shelter',
            'activity_center',
            'bureau_of_domestic_affairs',
            'advanced_pirate_economy',
            'mars_landing',
            'surveillance_network',
            'guiding_satellite',
            'nuclear_launch_facility',
            'moon_landing_date',
            'mars_landing_date',
            'wars_won',
            'wars_lost',
            'tax_id',
            'alliance_seniority',
            // 'baseball_team', // BBTeam class
            'gross_national_income',
            'gross_domestic_product',
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
            'money_looted',
            'total_infrastructure_destroyed',
            'total_infrastructure_lost',
            'vip',
            'commendations',
            'denouncements',
            'offensive_wars_count',
            'defensive_wars_count',
            // 'economic_policy', // EconomicPolicy class
            // 'social_policy', // SocialPolicy class
            // 'government_type', // GovernmentType class
            'credits_redeemed_this_month',
            'alliance_join_date',
            //            'awards',
            //            'bulletins',
            //            'bulletin_replies',
        ];
    }

    /**
     * @return string[]
     */
    public static function allianceSet(): array
    {
        return [
            'id',
            'name',
            'acronym',
            'score',
            'color',
            // 'date',
            // 'nations',
            'average_score',
            // 'treaties',
            // 'alliance_positions',
            'accept_members',
            'flag',
            'forum_link',
            'discord_link',
            'wiki_link',
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
            'rank',
            // 'awards',
            // 'bulletins',
            // 'sent_treaties',
            // 'received_treaties',
            // 'acceptmem',
            // 'forumlink',
            // 'irclink',
        ];
    }

    /**
     * @return string[]
     */
    public static function citySet(): array
    {
        return [
            'id',
            'nation_id',
            // 'nation',
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
            //            'nuke_date',
            //            'cities_discount'
        ];
    }

    /**
     * @return string[]
     */
    public static function bankRecordSet(): array
    {
        return [
            'id',
            'date',
            'sender_id',
            'sender_type',
            'receiver_id',
            'receiver_type',
            'banker_id',
            'note',
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
            'tax_id'
        ];
    }

    public static function warSet(): array
    {
        return [
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
    }
}
