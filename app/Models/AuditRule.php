<?php

namespace App\Models;

use App\Enums\AuditEvaluationStatus;
use App\Enums\AuditPriority;
use App\Enums\AuditTargetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRule extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => AuditTargetType::class,
            'priority' => AuditPriority::class,
            'enabled' => 'boolean',
            'definition' => 'array',
            'revision' => 'integer',
            'last_evaluation_status' => AuditEvaluationStatus::class,
            'last_evaluated_at' => 'datetime',
            'last_match_count' => 'integer',
            'last_evaluation_duration_ms' => 'integer',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(AuditResult::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
