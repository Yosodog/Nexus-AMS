<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a war plan to either a friendly or enemy alliance.
 *
 * @property int $id
 * @property int $war_plan_id
 * @property int $alliance_id
 * @property string $role
 * @property array|null $meta
 */
class WarPlanAlliance extends Model
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
     * @return BelongsTo<Alliance, self>
     */
    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class, 'alliance_id');
    }
}
