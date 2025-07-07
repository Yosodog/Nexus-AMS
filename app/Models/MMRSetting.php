<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MMRSetting extends Model
{
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

    public $table = "mmr_settings";
}
