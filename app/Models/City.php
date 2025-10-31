<?php

namespace App\Models;

use App\AutoSync\Concerns\AutoSyncsWithPoliticsAndWar;
use App\AutoSync\Contracts\SyncableWithPoliticsAndWar;
use App\AutoSync\SyncDefinition;
use App\GraphQL\Models\City as CityGraphQL;
use App\Services\CityQueryService;
use App\Services\GraphQLQueryBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model implements SyncableWithPoliticsAndWar
{
    use AutoSyncsWithPoliticsAndWar;
    use SoftDeletes;

    public $guarded = [];
    protected $table = "cities";

    protected $casts = [
        'date' => 'date',
        'powered' => 'boolean',
        'infrastructure' => 'float',
        'land' => 'float',
    ];

    /**
     * Upsert a city record from the GraphQL payload.
     *
     * @param CityGraphQL $graphQLCityModel
     * @return self
     */
    public static function updateFromAPI(CityGraphQL $graphQLCityModel): self
    {
        // Extract city data
        $cityData = collect((array)$graphQLCityModel)->only([
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
        ])->toArray();

        $city = self::withTrashed()->firstOrNew(['id' => $graphQLCityModel->id]);

        if ($city->trashed()) {
            $city->restore();
        }

        $city->fill($cityData);
        $city->save();

        return $city;
    }

    /**
     * Locate a city by its identifier.
     *
     * @param int $id
     * @return self
     */
    public static function getById(int $id): self
    {
        return self::where("id", $id)->firstOrFail();
    }

    /**
     * Retrieve the owning nation for the city.
     *
     * @return BelongsTo
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    /**
     * Determine whether the city's infrastructure is aligned to the expected increment.
     *
     * @return bool
     */
    public function isInfrastructureAligned(): bool
    {
        return $this->isMultipleOfFifty((float) $this->infrastructure);
    }

    /**
     * Determine whether the city's land is aligned to the expected increment.
     *
     * @return bool
     */
    public function isLandAligned(): bool
    {
        return $this->isMultipleOfFifty((float) $this->land);
    }

    /**
     * Evaluate whether the provided value is aligned to 50-unit steps.
     *
     * @param float $value
     * @return bool
     */
    protected function isMultipleOfFifty(float $value): bool
    {
        if ($value === 0.0) {
            return true;
        }

        $nearest = round($value / 50) * 50;

        return abs($value - $nearest) < 0.01;
    }

    /**
     * Describe how to synchronize cities from Politics & War.
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

                $arguments = [
                    'id' => count($ids) === 1
                        ? $ids[0]
                        : GraphQLQueryBuilder::literal('[' . implode(', ', $ids) . ']'),
                ];

                return CityQueryService::getMultipleCities(
                    $arguments,
                    max(1, min(count($ids), config('pw-sync.chunk_size', 100)))
                );
            },
            function ($record) {
                return self::updateFromAPI($record);
            },
            $staleAfter,
            ['name']
        );
    }
}
