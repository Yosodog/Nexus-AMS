<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationSignIns extends Model
{
    /**
     * @var string
     */
    public $table = "nation_sign_ins";

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string[]
     */
    protected $fillable = [
        'nation_id',
        'num_cities',
        'score',
        'wars_won',
        'wars_lost',
        'total_infrastructure_destroyed',
        'total_infrastructure_lost',

        // Military - current
        'soldiers',
        'tanks',
        'aircraft',
        'ships',
        'missiles',
        'nukes',
        'spies',

        // Military - performance
        'soldier_kills',
        'soldier_casualties',
        'tank_kills',
        'tank_casualties',
        'aircraft_kills',
        'aircraft_casualties',
        'ship_kills',
        'ship_casualties',
        'missile_kills',
        'missile_casualties',
        'nuke_kills',
        'nuke_casualties',
        'spy_kills',
        'spy_casualties',

        // Resources
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
        'credits',

        'created_at',
    ];

    /**
     * @return BelongsTo
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nations::class);
    }
}
