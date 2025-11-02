<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a friendly assignment against a war plan target.
 *
 * @property int $id
 * @property int $war_plan_id
 * @property int $war_plan_target_id
 * @property int $friendly_nation_id
 * @property int|null $war_plan_squad_id
 * @property float $match_score
 * @property string $status
 * @property bool $is_overridden
 * @property bool $is_locked
 * @property array|null $meta
 */
class WarPlanAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'is_overridden' => 'bool',
        'is_locked' => 'bool',
    ];

    /**
     * @return BelongsTo<WarPlan, self>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarPlan::class, 'war_plan_id');
    }

    /**
     * @return BelongsTo<WarPlanTarget, self>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WarPlanTarget::class, 'war_plan_target_id');
    }

    /**
     * @return BelongsTo<Nation, self>
     */
    public function friendlyNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'friendly_nation_id');
    }

    /**
     * @return BelongsTo<WarPlanSquad, self>
     */
    public function squad(): BelongsTo
    {
        return $this->belongsTo(WarPlanSquad::class, 'war_plan_squad_id');
    }
}
