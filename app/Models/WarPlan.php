<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a proactive alliance war plan.
 *
 * @property int $id
 * @property string $name
 * @property string $plan_type
 * @property string $status
 * @property array|null $options
 * @property int $preferred_nations_per_target
 * @property int $max_squad_size
 * @property int $squad_cohesion_tolerance
 * @property int $activity_window_hours
 * @property bool $suppress_counters_when_active
 * @property \Carbon\CarbonInterface|null $activated_at
 * @property \Carbon\CarbonInterface|null $archived_at
 * @property \Carbon\CarbonInterface|null $assignments_published_at
 */
class WarPlan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'suppress_counters_when_active' => 'bool',
        'activated_at' => 'datetime',
        'archived_at' => 'datetime',
        'assignments_published_at' => 'datetime',
    ];

    /**
     * @return HasMany<WarPlanAlliance>
     */
    public function alliances(): HasMany
    {
        return $this->hasMany(WarPlanAlliance::class);
    }

    /**
     * @return HasMany<WarPlanAlliance>
     */
    public function friendlyAlliances(): HasMany
    {
        return $this->alliances()->where('role', 'friendly');
    }

    /**
     * @return HasMany<WarPlanAlliance>
     */
    public function enemyAlliances(): HasMany
    {
        return $this->alliances()->where('role', 'enemy');
    }

    /**
     * @return HasMany<WarPlanTarget>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(WarPlanTarget::class);
    }

    /**
     * @return HasMany<WarPlanSquad>
     */
    public function squads(): HasMany
    {
        return $this->hasMany(WarPlanSquad::class);
    }

    /**
     * @return HasMany<WarPlanAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WarPlanAssignment::class);
    }

    /**
     * Scope to active plans.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to plans currently under planning.
     */
    public function scopePlanning(Builder $query): Builder
    {
        return $query->where('status', 'planning');
    }
}
