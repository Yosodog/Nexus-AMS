<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class NationMilitary extends Model
{
    use SoftDeletes;

    /**
     * Zero defaults used when a partial subscription creates the first snapshot.
     *
     * @var array<string, int>
     */
    public const DEFAULT_COUNTERS = [
        'soldiers' => 0,
        'tanks' => 0,
        'aircraft' => 0,
        'ships' => 0,
        'missiles' => 0,
        'nukes' => 0,
        'spies' => 0,
        'soldiers_today' => 0,
        'tanks_today' => 0,
        'aircraft_today' => 0,
        'ships_today' => 0,
        'missiles_today' => 0,
        'nukes_today' => 0,
        'spies_today' => 0,
        'soldier_casualties' => 0,
        'soldier_kills' => 0,
        'tank_casualties' => 0,
        'tank_kills' => 0,
        'aircraft_casualties' => 0,
        'aircraft_kills' => 0,
        'ship_casualties' => 0,
        'ship_kills' => 0,
        'missile_casualties' => 0,
        'missile_kills' => 0,
        'nuke_casualties' => 0,
        'nuke_kills' => 0,
        'spy_casualties' => 0,
        'spy_kills' => 0,
        'spy_attacks' => 0,
    ];

    protected $table = 'nation_military';

    protected $guarded = [];

    /**
     * Relationship to the Nation model.
     * Each NationMilitary record belongs to a single Nation.
     *
     * @return BelongsTo
     */
    public function nation()
    {
        return $this->belongsTo(Nation::class);
    }
}
