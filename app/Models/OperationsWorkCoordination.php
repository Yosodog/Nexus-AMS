<?php

namespace App\Models;

use Database\Factories\OperationsWorkCoordinationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationsWorkCoordination extends Model
{
    public const ACTIVE_KEY_VALUE = 1;

    /** @use HasFactory<OperationsWorkCoordinationFactory> */
    use HasFactory;

    protected $table = 'operations_work_coordination';

    protected $fillable = [
        'work_key',
        'occurrence_key',
        'source_type',
        'source_fingerprint',
        'team_override_key',
        'assignee_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'triage_acknowledged_at',
        'triage_acknowledged_by_user_id',
        'escalated_at',
        'escalated_by_user_id',
        'escalation_reason',
        'first_seen_at',
        'last_seen_at',
        'source_updated_at',
        'first_action_at',
        'last_activity_at',
        'closed_at',
        'lock_version',
        'active_key',
    ];

    protected $attributes = [
        'lock_version' => 1,
        'active_key' => self::ACTIVE_KEY_VALUE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'triage_acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'first_action_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'lock_version' => 'integer',
            'active_key' => 'integer',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function triageAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triage_acknowledged_by_user_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationsWorkEvent::class, 'coordination_id');
    }

    public function watches(): HasMany
    {
        return $this->hasMany(OperationsWorkWatch::class, 'coordination_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_key', self::ACTIVE_KEY_VALUE);
    }
}
