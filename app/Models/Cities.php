<?php

namespace App\Models;

use App\GraphQL\Models\City;
use Illuminate\Database\Eloquent\Model;

class Cities extends Model
{
    protected $table = "cities";
    public $guarded = [];

    /**
     * @param City $graphQLCityModel
     * @return self
     */
    public static function updateFromAPI(City $graphQLCityModel): self
    {
        // Extract city data
        $cityData = collect((array) $graphQLCityModel)->only([
            'id', 'nation_id', 'name', 'date', 'infrastructure', 'land', 'powered',
            'oil_power', 'wind_power', 'coal_power', 'nuclear_power',
            'coal_mine', 'oil_well', 'uranium_mine', 'barracks', 'farm',
            'police_station', 'hospital', 'recycling_center', 'subway',
            'supermarket', 'bank', 'shopping_mall', 'stadium',
            'lead_mine', 'iron_mine', 'bauxite_mine', 'oil_refinery',
            'aluminum_refinery', 'steel_mill', 'munitions_factory',
            'factory', 'hangar', 'drydock'
        ])->toArray();

        // Use `updateOrCreate` to handle both creation and update
        return self::updateOrCreate(['id' => $graphQLCityModel->id], $cityData);
    }
}
