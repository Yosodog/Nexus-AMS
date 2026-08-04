<?php

namespace App\Models;

use App\Domain\Milcom\Enums\ObjectiveStatus;
use App\Domain\Milcom\Enums\PriorityTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MilcomObjective extends Model
{
    public const OPEN_KEY_VALUE = 1;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (MilcomObjective $objective): void {
            if ($objective->status instanceof ObjectiveStatus && ! $objective->status->isOpen()) {
                $objective->open_key = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'priority_tier' => PriorityTier::class,
            'status' => ObjectiveStatus::class,
            'blocker_summary' => 'array',
            'metadata' => 'array',
            'deadline_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'engaged_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'operation_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Nation::class, 'target_nation_id');
    }

    public function sourceIncident(): BelongsTo
    {
        return $this->belongsTo(MilcomIncident::class, 'source_incident_id');
    }

    public function latestRecommendationRun(): BelongsTo
    {
        return $this->belongsTo(MilcomRecommendationRun::class, 'latest_recommendation_run_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MilcomAssignment::class, 'objective_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MilcomObjectiveRecommendation::class, 'objective_id');
    }

    public function latestRecommendation(): HasOne
    {
        return $this->hasOne(MilcomObjectiveRecommendation::class, 'objective_id')->latestOfMany();
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(MilcomDispatch::class, 'objective_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MilcomIncident::class, 'objective_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MilcomEvent::class, 'objective_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            ObjectiveStatus::Completed->value,
            ObjectiveStatus::Cancelled->value,
            ObjectiveStatus::Expired->value,
        ]);
    }
}
