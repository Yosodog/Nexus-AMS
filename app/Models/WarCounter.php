<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reactive counter assignment against an aggressor nation.
 *
 * @property int $id
 * @property int $aggressor_nation_id
 * @property string $status
 * @property int $team_size
 * @property string $war_declaration_type
 * @property int|null $suppressed_by_plan_id
 * @property array|null $settings
 * @property \Carbon\CarbonInterface|null $finalized_at
 * @property \Carbon\CarbonInterface|null $archived_at
 * @property \Carbon\CarbonInterface|null $last_war_declared_at
 */
class WarCounter extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'finalized_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_war_declared_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Nation, self>
     */
    public function aggressor(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'aggressor_nation_id');
    }

    /**
     * @return BelongsTo<WarPlan, self>
     */
    public function suppressingPlan(): BelongsTo
    {
        return $this->belongsTo(WarPlan::class, 'suppressed_by_plan_id');
    }

    /**
     * @return HasMany<WarCounterAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WarCounterAssignment::class);
    }
}
