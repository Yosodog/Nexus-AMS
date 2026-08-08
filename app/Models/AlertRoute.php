<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use Database\Factories\AlertRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRoute extends Model
{
    /** @use HasFactory<AlertRouteFactory> */
    use HasFactory;

    protected $attributes = [
        'minimum_severity' => 'normal',
        'is_active' => true,
    ];

    protected $fillable = [
        'alliance_id',
        'alert_destination_id',
        'created_by_user_id',
        'name',
        'event_key',
        'minimum_severity',
        'filter_config',
        'delivery_policy',
        'is_active',
        'active_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'minimum_severity' => AlertSeverity::class,
            'filter_config' => 'array',
            'delivery_policy' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(AlertDestination::class, 'alert_destination_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }
}
