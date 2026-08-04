<?php

namespace App\Models;

use App\Domain\Milcom\Enums\OperationStatus;
use App\Domain\Milcom\Enums\OperationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class MilcomOperation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => OperationType::class,
            'status' => OperationStatus::class,
            'metadata' => 'array',
            'failure_details' => 'array',
            'deadline_at' => 'datetime',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function alliances(): HasMany
    {
        return $this->hasMany(MilcomOperationAlliance::class, 'operation_id');
    }

    public function nations(): HasMany
    {
        return $this->hasMany(MilcomOperationNation::class, 'operation_id');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(MilcomObjective::class, 'operation_id');
    }

    public function recommendationRuns(): HasMany
    {
        return $this->hasMany(MilcomRecommendationRun::class, 'operation_id');
    }

    public function assignmentsThroughObjectives(): HasManyThrough
    {
        return $this->hasManyThrough(
            MilcomAssignment::class,
            MilcomObjective::class,
            'operation_id',
            'objective_id',
        );
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(MilcomDispatch::class, 'operation_id');
    }

    public function assignmentDeliveries(): HasMany
    {
        return $this->hasMany(MilcomAssignmentDelivery::class, 'operation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MilcomEvent::class, 'operation_id');
    }

    public function scopePlans(Builder $query): Builder
    {
        return $query->where('type', OperationType::Plan->value);
    }

    public function scopeCounters(Builder $query): Builder
    {
        return $query->where('type', OperationType::Counter->value);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OperationStatus::Completed->value,
            OperationStatus::Archived->value,
        ]);
    }
}
