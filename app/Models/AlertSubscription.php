<?php

namespace App\Models;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertSubscriptionStatus;
use App\Enums\AlertSubscriptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property AlertSubscriptionType $type
 * @property string|null $name
 * @property array<string, mixed> $config
 * @property array<string, mixed>|null $last_observed_state
 * @property bool|null $last_condition
 * @property bool $is_active
 * @property int $cooldown_minutes
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $last_triggered_at
 */
class AlertSubscription extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
        'status' => 'active',
        'cooldown_minutes' => 60,
        'delivery_mode' => 'immediate',
        'discord_enabled' => true,
        'rearm_percent' => 1,
    ];

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'config',
        'target_type',
        'target_id',
        'filter_config',
        'last_observed_state',
        'last_condition',
        'is_active',
        'status',
        'status_reason',
        'cooldown_minutes',
        'delivery_mode',
        'discord_enabled',
        'rearm_percent',
        'timezone',
        'expires_at',
        'last_evaluated_at',
        'last_triggered_at',
        'last_source_version',
        'last_source_observed_at',
        'active_fingerprint',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertSubscriptionType::class,
            'config' => 'array',
            'target_id' => 'integer',
            'filter_config' => 'array',
            'last_observed_state' => 'array',
            'last_condition' => 'boolean',
            'is_active' => 'boolean',
            'status' => AlertSubscriptionStatus::class,
            'cooldown_minutes' => 'integer',
            'delivery_mode' => AlertDeliveryMode::class,
            'discord_enabled' => 'boolean',
            'rearm_percent' => 'decimal:2',
            'expires_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'last_triggered_at' => 'datetime',
            'last_source_observed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', AlertSubscriptionStatus::Active->value)
            ->where(fn (Builder $expiry): Builder => $expiry
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertSubscriptionEvent::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function displayName(): string
    {
        if (is_string($this->name) && trim($this->name) !== '') {
            return $this->name;
        }

        return match ($this->type) {
            AlertSubscriptionType::Nation => 'Nation #'.(int) ($this->config['target_id'] ?? 0),
            AlertSubscriptionType::Alliance => 'Alliance #'.(int) ($this->config['target_id'] ?? 0),
            AlertSubscriptionType::Market => ucfirst((string) ($this->config['resource'] ?? 'resource')).' price',
        };
    }
}
