<?php

namespace App\Models;

use App\GraphQL\Models\City as CityGraphQL;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class City extends Model
{
    use SoftDeletes;

    public $guarded = [];
    protected $table = "cities";

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
}
