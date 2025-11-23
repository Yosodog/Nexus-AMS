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
}
