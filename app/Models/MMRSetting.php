<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MMRSetting extends Model
{
    public const MIN_SURCHARGE_PCT = 0.00;

    public const MAX_SURCHARGE_PCT = 100.00;

    /**
     * @var string[]
     */
    protected $fillable = [
        'resource',
        'enabled',
        'surcharge_pct',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'enabled' => 'boolean',
        'surcharge_pct' => 'float',
    ];

    public $table = 'mmr_settings';

    public static function normalizeSurchargePercentage(float $surchargePercentage): float
    {
        return round(min(max($surchargePercentage, self::MIN_SURCHARGE_PCT), self::MAX_SURCHARGE_PCT), 2);
    }
}
