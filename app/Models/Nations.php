<?php

namespace App\Models;

use App\GraphQL\Models\Nation;
use Illuminate\Database\Eloquent\Model;

class Nations extends Model
{
    protected $guarded = [];

    protected $table = "nations";

    // Projects array to interpret bit values
    protected array $defaultProjectsArray = [
        'iron_works', 'bauxite_works', 'arms_stockpile', 'emergency_gasoline_reserve', 'mass_irrigation',
        'international_trade_center', 'missile_launch_pad', 'nuclear_research_facility', 'iron_dome',
        'vital_defense_system', 'central_intelligence_agency', 'center_for_civil_engineering',
        'propaganda_bureau', 'uranium_enrichment_program', 'urban_planning', 'advanced_urban_planning',
        'space_program', 'spy_satellite', 'moon_landing', 'pirate_economy', 'recycling_initiative',
        'telecommunications_satellite', 'green_technologies', 'arable_land_agency', 'clinical_research_center',
        'specialized_police_training_program', 'advanced_engineering_corps', 'government_support_agency',
        'research_and_development_center', 'activity_center', 'metropolitan_planning', 'military_salvage',
        'fallout_shelter', 'bureau_of_domestic_affairs', 'advanced_pirate_economy', 'mars_landing',
        'surveillance_network', 'guiding_satellite', 'nuclear_launch_facility'
    ];

    /**
     * Will be set to what projects this nation has. Must run getProjectsAttribute() first.
     *
     * @var array
     */
    public array $projectsArray;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function resources()
    {
        return $this->hasOne(NationResources::class, 'nation_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function military()
    {
        return $this->hasOne(NationMilitary::class, 'nation_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function alliance()
    {
        return $this->belongsTo(Alliances::class, "alliance_id", "id");
    }

    /**
     * Interpret project_bits and return an associative array indicating project ownership.
     *
     * @return array
     */
    public function getProjectsAttribute(): array
    {
        $projects = [];
        $bitString = str_pad($this->project_bits, count($this->defaultProjectsArray), '0', STR_PAD_LEFT);

        foreach (array_reverse(str_split($bitString)) as $index => $bit) {
            $projects[$this->defaultProjectsArray[$index]] = $bit === '1';
        }

        $this->projectsArray = $projects;

        return $projects;
    }

    /**
     * @param Nation $graphQLNationModel
     * @return self
     */
    public static function updateFromAPI(Nation $graphQLNationModel): self
    {
        // Extract nation data
        $nationData = collect((array) $graphQLNationModel)->only([
            'id', 'alliance_id', 'alliance_position', 'alliance_position_id', 'nation_name',
            'leader_name', 'continent', 'war_policy_turns', 'domestic_policy_turns', 'color',
            'num_cities', 'score', 'update_tz', 'population', 'flag', 'vacation_mode_turns',
            'beige_turns', 'espionage_available', 'discord', 'discord_id', 'turns_since_last_city',
            'turns_since_last_project', 'projects', 'project_bits', 'wars_won', 'wars_lost',
            'tax_id', 'alliance_seniority', 'gross_national_income', 'gross_domestic_product',
            'vip', 'commendations', 'denouncements', 'offensive_wars_count', 'defensive_wars_count'
        ])->toArray();

        // Check if the nation already exists
        $nation = self::find($graphQLNationModel->id);

        if ($nation) {
            // Update the existing Nation record
            $nation->update($nationData);
        } else {
            // Create a new Nation record
            $nation = self::create($nationData);
        }

        // Conditional creation of resources data
        if ($graphQLNationModel->money > 0) { // No way they have $0 :(
            $resourcesData = collect((array) $graphQLNationModel)->only([
                'money', 'coal', 'oil', 'uranium', 'iron', 'bauxite', 'lead', 'gasoline',
                'munitions', 'steel', 'aluminum', 'food', 'credits'
            ])->toArray();
            $nation->resources()->updateOrCreate(['nation_id' => $nation->id], $resourcesData);
        }

        // Create military data
        $militaryData = collect((array) $graphQLNationModel)->only([
            'soldiers', 'tanks', 'aircraft', 'ships', 'missiles', 'nukes', 'spies', 'soldiers_today',
            'tanks_today', 'aircraft_today', 'ships_today', 'missiles_today', 'nukes_today',
            'spies_today', 'soldier_casualties', 'soldier_kills', 'tank_casualties', 'tank_kills',
            'aircraft_casualties', 'aircraft_kills', 'ship_casualties', 'ship_kills',
            'missile_casualties', 'missile_kills', 'nuke_casualties', 'nuke_kills',
            'spy_casualties', 'spy_kills'
        ])->toArray();
        $nation->military()->updateOrCreate(['nation_id' => $nation->id], $militaryData);

        return $nation;
    }
}
