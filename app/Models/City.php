<?php

namespace App\Models;

use App\GraphQL\Models\City as CityGraphQL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model
{
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

        // Use `updateOrCreate` to handle both creation and update
        return self::updateOrCreate(['id' => $graphQLCityModel->id], $cityData);
    }

    /**
     * @param int $id
     * @return self
     */
    public static function getById(int $id): self
    {
        return self::where("id", $id)->firstOrFail();
    }

    /**
     * @return BelongsTo
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    public function isInfrastructureAligned(): bool
    {
        return $this->isMultipleOfFifty((float) $this->infrastructure);
    }

    public function isLandAligned(): bool
    {
        return $this->isMultipleOfFifty((float) $this->land);
    }

    protected function isMultipleOfFifty(float $value): bool
    {
        if ($value === 0.0) {
            return true;
        }

        $nearest = round($value / 50) * 50;

        return abs($value - $nearest) < 0.01;
    }
}
