<?php

namespace App\Models;

use App\Domain\Milcom\Enums\RecommendationRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilcomRecommendationRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RecommendationRunStatus::class,
            'failure_details' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(MilcomObjective::class, 'objective_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(MilcomReadinessSnapshot::class, 'recommendation_run_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MilcomObjectiveRecommendation::class, 'recommendation_run_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MilcomAssignment::class, 'recommendation_run_id');
    }
}
