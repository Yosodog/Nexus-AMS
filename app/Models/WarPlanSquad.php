<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a friendly strike squad within a war plan.
 *
 * @property int $id
 * @property int $war_plan_id
 * @property string $label
 * @property int $round
 * @property float $cohesion_score
 * @property array|null $meta
 */
class WarPlanSquad extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * @return BelongsTo<WarPlan, self>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarPlan::class, 'war_plan_id');
    }

    /**
     * @return HasMany<WarPlanAssignment>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(WarPlanAssignment::class, 'war_plan_squad_id');
    }
}
