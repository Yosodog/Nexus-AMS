<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents an enemy nation tracked within a war plan along with its TPS.
 *
 * @property int $id
 * @property int $war_plan_id
 * @property int $nation_id
 * @property string $preferred_war_type
 * @property float $target_priority_score
 * @property array|null $meta
 * @property \Carbon\CarbonInterface|null $computed_at
 */
class WarPlanTarget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'computed_at' => 'datetime',
        'preferred_war_type' => 'string',
    ];

    /**
     * @return BelongsTo<WarPlan, self>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarPlan::class, 'war_plan_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'nation_id');
    }

    /**
     * @return HasMany<WarPlanAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WarPlanAssignment::class);
    }
}
