<?php

namespace App\Models;

use App\Enums\AlertAudience;
use App\Enums\AlertSensitivity;
use App\Enums\AlertSeverity;
use Database\Factories\AlertOccurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertOccurrence extends Model
{
    /** @use HasFactory<AlertOccurrenceFactory> */
    use HasFactory;

    protected $attributes = [
        'schema_version' => 1,
        'severity' => 'normal',
        'sensitivity' => 'member',
        'is_test' => false,
    ];

    protected $fillable = [
        'event_key',
        'schema_version',
        'alliance_id',
        'audience_user_id',
        'source_type',
        'source_id',
        'source_version',
        'subject_type',
        'subject_id',
        'deep_link_path',
        'severity',
        'sensitivity',
        'payload',
        'occurred_at',
        'observed_at',
        'received_at',
        'stale_at',
        'correlation_key',
        'dedupe_key',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'severity' => AlertSeverity::class,
            'sensitivity' => AlertSensitivity::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'observed_at' => 'datetime',
            'received_at' => 'datetime',
            'stale_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }

    public function audienceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audience_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function audience(AlertAudience $fallback = AlertAudience::Administrator): AlertAudience
    {
        return $this->audience_user_id !== null ? AlertAudience::Member : $fallback;
    }
}
