<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MMRTier extends Model
{
    public $table = "mmr_tiers";

    protected $fillable = [
        'city_count',
        'steel',
        'aluminum',
        'munitions',
        'uranium',
        'food',
        'barracks',
        'factories',
        'hangars',
        'drydocks',
        'missiles',
        'nukes',
        'spies',
    ];

    protected $casts = [
        'steel' => 'integer',
        'aluminum' => 'integer',
        'munitions' => 'integer',
        'uranium' => 'integer',
        'food' => 'integer',
        'barracks' => 'integer',
        'factories' => 'integer',
        'hangars' => 'integer',
        'drydocks' => 'integer',
        'missiles' => 'integer',
        'nukes' => 'integer',
        'spies' => 'integer',
    ];
}
