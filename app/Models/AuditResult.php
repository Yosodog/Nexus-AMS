<?php

namespace App\Models;

use App\Enums\AuditTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditResult extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => AuditTargetType::class,
            'details' => 'array',
            'first_detected_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'waived_until' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AuditRule::class, 'audit_rule_id');
    }

    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by_user_id');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    public function isWaived(): bool
    {
        return $this->waived_until !== null && $this->waived_until->isFuture();
    }
}
