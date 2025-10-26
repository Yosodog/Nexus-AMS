<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MMRTier extends Model
{
    public $table = "mmr_tiers";

    protected $fillable = [
        'city_count',
        'money',
        'steel',
        'aluminum',
        'munitions',
        'uranium',
        'food',
        'gasoline',
        'barracks',
        'factories',
        'hangars',
        'drydocks',
        'missiles',
        'nukes',
        'spies',
    ];

    protected $casts = [
        'money' => 'integer',
        'steel' => 'integer',
        'aluminum' => 'integer',
        'munitions' => 'integer',
        'uranium' => 'integer',
        'food' => 'integer',
        'gasoline' => 'integer',
        'barracks' => 'integer',
        'factories' => 'integer',
        'hangars' => 'integer',
        'drydocks' => 'integer',
        'missiles' => 'integer',
        'nukes' => 'integer',
        'spies' => 'integer',
    ];
}
