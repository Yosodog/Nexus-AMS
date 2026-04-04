<?php

namespace App\Models;

use App\GraphQL\Models\City as CityGraphQL;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

class City extends Model
{
    use SoftDeletes;

    public $guarded = [];

    protected $table = 'cities';

    protected $casts = [
        'date' => 'date',
        'nuke_date' => 'date',
        'powered' => 'boolean',
        'infrastructure' => 'float',
        'land' => 'float',
    ];

    public static function updateFromAPI(CityGraphQL $graphQLCityModel): self
    {
        $cityData = collect((array) $graphQLCityModel)->only([
            'id',
            'nation_id',
            'name',
            'date',
            'nuke_date',
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
        ])->toArray();

        $cityData = self::normalizeApiPayload($cityData);

        return self::updateOrCreate(['id' => $graphQLCityModel->id], $cityData);
    }

    public static function normalizeApiPayload(array $cityData): array
    {
        if (array_key_exists('nuke_date', $cityData)) {
            $cityData['nuke_date'] = self::normalizeApiDateValue($cityData['nuke_date']);
        }

        return $cityData;
    }

    public static function normalizeApiDateValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $dateValue = trim((string) $value);

        if ($dateValue === '' || str_starts_with($dateValue, '-') || str_starts_with($dateValue, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($dateValue)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    public static function getById(int $id): self
    {
        return self::where('id', $id)->firstOrFail();
    }

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
