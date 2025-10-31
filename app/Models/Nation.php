<?php

namespace App\Models;

use App\AutoSync\AutoSyncManager;
use App\AutoSync\Concerns\AutoSyncsWithPoliticsAndWar;
use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\AutoSync\SyncDefinition;
use App\GraphQL\Models\Nation as NationGraphQL;
use App\Services\GraphQLQueryBuilder;
use App\Services\NationQueryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Nation extends Model implements SyncableWithPoliticsAndWar
{
    use AutoSyncsWithPoliticsAndWar;
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

    /**
     * Upsert the nation and related aggregates using API payload data.
     *
     * @param NationGraphQL $graphQLNationModel
     * @return self
     */
    public static function updateFromAPI(NationGraphQL $graphQLNationModel): self
    {
        // Extract only non-null values so existing attributes remain untouched.
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
        $nation = self::withTrashed()->find($graphQLNationModel->id);

        if ($nation) {
            if ($nation->trashed()) {
                $nation->restore();
            }

            // Use `fill()` to update only provided values without affecting existing ones
            $nation->fill($nationData);

            // Check if there are any changes before saving (prevents unnecessary queries)
            if ($nation->isDirty()) {
                $nation->save();
            } else {
                $nation->touch();
            }
        } else {
            // Create a new Nation record
            $nation = self::create($nationData);
        }

        $syncedCityIds = [];
        $syncedResourceIds = [];
        $syncedMilitaryIds = [];

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
                $resourceModel = NationResources::withTrashed()->firstOrNew(['nation_id' => $nation->id]);

                if ($resourceModel->trashed()) {
                    $resourceModel->restore();
                }

                $resourceModel->fill($resourcesData);
                $resourceModel->save();

                $syncedResourceIds[] = $resourceModel->getAttribute('nation_id');
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
            $militaryModel = NationMilitary::withTrashed()->firstOrNew(['nation_id' => $nation->id]);

            if ($militaryModel->trashed()) {
                $militaryModel->restore();
            }

            $militaryModel->fill($militaryData);
            $militaryModel->save();

            $syncedMilitaryIds[] = $militaryModel->getAttribute('nation_id');
        }

        if (!is_null($graphQLNationModel->cities)) {
            foreach ($graphQLNationModel->cities as $city) {
                $cityModel = City::updateFromAPI($city);

                if ($cityModel->getKey() !== null) {
                    $syncedCityIds[] = $cityModel->getKey();
                }
            }
        }

        if (! empty($syncedCityIds) || ! empty($syncedResourceIds) || ! empty($syncedMilitaryIds)) {
            /** @var AutoSyncManager $manager */
            $manager = app(AutoSyncManager::class);

            if (! empty($syncedCityIds)) {
                $manager->markModelsSynced(City::class, array_unique($syncedCityIds));
            }

            if (! empty($syncedResourceIds)) {
                $manager->markModelsSynced(NationResources::class, array_unique($syncedResourceIds));
            }

            if (! empty($syncedMilitaryIds)) {
                $manager->markModelsSynced(NationMilitary::class, array_unique($syncedMilitaryIds));
            }
        }

        return $nation;
    }

    /**
     * Get the attached resources snapshot for the nation.
     *
     * @return HasOne
     */
    public function resources()
    {
        return $this->hasOne(NationResources::class, 'nation_id');
    }

    /**
     * Get the attached military snapshot for the nation.
     *
     * @return HasOne
     */
    public function military()
    {
        return $this->hasOne(NationMilitary::class, 'nation_id');
    }

    /**
     * Retrieve the latest sign-in relationship for the nation.
     *
     * @return HasOne
     */
    public function latestSignIn()
    {
        return $this->hasOne(NationSignIn::class, 'nation_id')->latestOfMany();
    }

    /**
     * Retrieve a nation by its identifier.
     *
     * @param int $nation_id
     * @return Nation
     */
    public static function getNationById(int $nation_id): Nation
    {
        return self::where("nation_id", $nation_id)->firstOrFail();
    }

    /**
     * Retrieve the owning alliance for the nation.
     *
     * @return BelongsTo
     */
    public function alliance()
    {
        return $this->belongsTo(Alliance::class, "alliance_id", "id");
    }

    /**
     * Retrieve AMS accounts that belong to the nation.
     *
     * @return HasMany
     */
    public function accounts()
    {
        return $this->hasMany(Account::class, "nation_id");
    }

    /**
     * Retrieve sign-in records for the nation.
     *
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

    /**
     * Describe how to synchronize nations from Politics & War.
     *
     * @return SyncDefinition
     */
    public static function getAutoSyncDefinition(): SyncDefinition
    {
        $staleAfter = config('pw-sync.staleness.' . self::class);

        return new SyncDefinition(
            self::class,
            'id',
            function (array $ids, array $context = []) {
                $ids = array_values(array_unique(array_map('intval', $ids)));

                if (empty($ids)) {
                    return [];
                }

                $withCities = (bool) ($context['include_cities'] ?? false);

                if ($withCities && count($ids) === 1) {
                    return [NationQueryService::getNationAndCitiesById($ids[0])];
                }

                $arguments = [
                    'id' => count($ids) === 1
                        ? $ids[0]
                        : GraphQLQueryBuilder::literal('[' . implode(', ', $ids) . ']'),
                ];

                return NationQueryService::getMultipleNations(
                    $arguments,
                    max(1, min(count($ids), config('pw-sync.chunk_size', 100))),
                    $withCities,
                    false,
                    false
                );
            },
            function ($record) {
                return self::updateFromAPI($record);
            },
            $staleAfter,
            ['nation_name', 'leader_name'],
            function (array $ids, array $context = []): array {
                // Automatically include city payloads for single-nation refreshes so we avoid a second follow-up sync.
                if (! array_key_exists('include_cities', $context)) {
                    $context['include_cities'] = count($ids) === 1;
                }

                return $context;
            },
            [
                [
                    'when' => ['include_cities' => true],
                    'also' => [[]],
                ],
            ]
        );
    }
}
