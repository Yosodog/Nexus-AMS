<?php

namespace App\Models;

use App\Domain\Milcom\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilcomAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'is_locked' => 'boolean',
            'factor_explanations' => 'array',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'engaged_at' => 'datetime',
            'completed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class, 'objective_id');
    }

    public function friendlyNation(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'friendly_nation_id');
    }

    public function recommendationRun(): BelongsTo
    {
        return $this->belongsTo(MilcomRecommendationRun::class, 'recommendation_run_id');
    }

    public function declaredWar(): BelongsTo
    {
        return $this->belongsTo(War::class, 'declared_war_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MilcomEvent::class, 'assignment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MilcomAssignmentDelivery::class, 'assignment_id');
    }
}
