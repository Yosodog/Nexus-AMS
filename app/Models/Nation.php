<?php

namespace App\Models;

use App\GraphQL\Models\Nation as NationGraphQL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Nation extends Model
{
    use Notifiable, SoftDeletes;

    /**
     * Will be set to what projects this nation has. Must run getProjectsAttribute() first.
     *
     * @var array
     */
    public array $projectsArray;
    protected $guarded = [];

    // Projects array to interpret bit values
    protected $table = "nations";
    protected array $defaultProjectsArray = [
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
        'activity_center',
        'metropolitan_planning',
        'military_salvage',
        'fallout_shelter',
        'bureau_of_domestic_affairs',
        'advanced_pirate_economy',
        'mars_landing',
        'surveillance_network',
        'guiding_satellite',
        'nuclear_launch_facility'
    ];

    public static function updateFromAPI(NationGraphQL $graphQLNationModel): self
    {
        // Extract only non-null values
        $nationData = collect((array)$graphQLNationModel)
            ->only([
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
            ])
            ->filter(fn($value) => $value !== null) // Remove null values
            ->toArray();

        // Check if the nation already exists
        $nation = self::find($graphQLNationModel->id);

        if ($nation) {
            // Use `fill()` to update only provided values without affecting existing ones
            $nation->fill($nationData);

            // Check if there are any changes before saving (prevents unnecessary queries)
            if ($nation->isDirty()) {
                $nation->save();
            }
        } else {
            // Create a new Nation record
            $nation = self::create($nationData);
        }

        // Conditional update for resources
        if (!is_null($graphQLNationModel->money)) {
            $resourcesData = collect((array)$graphQLNationModel)
                ->only([
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
                ])
                ->filter(fn($value) => $value !== null)
                ->toArray();

            if (!empty($resourcesData)) {
                $nation->resources()->updateOrCreate(['nation_id' => $nation->id], $resourcesData);
            }
        }

        // Conditional update for military
        $militaryData = collect((array)$graphQLNationModel)
            ->only([
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
            ])
            ->filter(fn($value) => $value !== null)
            ->toArray();

        if (!empty($militaryData)) {
            $nation->military()->updateOrCreate(['nation_id' => $nation->id], $militaryData);
        }

        if (!is_null($graphQLNationModel->cities)) {
            foreach ($graphQLNationModel->cities as $city) {
                City::updateFromAPI($city);
            }
        }

        return $nation;
    }

    /**
     * @return HasOne
     */
    public function resources()
    {
        return $this->hasOne(NationResources::class, 'nation_id');
    }

    /**
     * @return HasOne
     */
    public function military()
    {
        return $this->hasOne(NationMilitary::class, 'nation_id');
    }

    /**
     * @return HasOne
     */
    public function latestSignIn()
    {
        return $this->hasOne(NationSignIn::class, 'nation_id')->latestOfMany();
    }

    /**
     * @param int $nation_id
     * @return Nation
     */
    public static function getNationById(int $nation_id): Nation
    {
        return self::where("nation_id", $nation_id)->firstOrFail();
    }

    /**
     * @return BelongsTo
     */
    public function alliance()
    {
        return $this->belongsTo(Alliance::class, "alliance_id", "id");
    }

    /**
     * @return HasMany
     */
    public function accounts()
    {
        return $this->hasMany(Account::class, "nation_id");
    }

    /**
     * @return HasMany
     */
    public function signIns(): HasMany
    {
        return $this->hasMany(NationSignIn::class, 'nation_id');
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
}
