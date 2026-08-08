<?php

namespace App\Models;

use App\Enums\AlertDestinationHealth;
use App\Enums\AlertDestinationKind;
use Database\Factories\AlertDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertDestination extends Model
{
    /** @use HasFactory<AlertDestinationFactory> */
    use HasFactory;

    protected $attributes = [
        'health_status' => 'unverified',
    ];

    protected $fillable = [
        'alliance_id',
        'created_by_user_id',
        'name',
        'kind',
        'guild_id',
        'channel_id',
        'mention_role_ids',
        'health_status',
        'fingerprint',
        'last_verified_at',
        'last_succeeded_at',
        'last_failed_at',
        'last_failure_code',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AlertDestinationKind::class,
            'mention_role_ids' => 'array',
            'health_status' => AlertDestinationHealth::class,
            'last_verified_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function alliance(): BelongsTo
    {
        return $this->belongsTo(Alliance::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(AlertRoute::class);
    }
}
