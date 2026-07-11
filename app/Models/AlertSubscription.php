<?php

namespace App\Models;

use App\Enums\AlertSubscriptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'config',
        'last_observed_state',
        'last_condition',
        'is_active',
        'cooldown_minutes',
        'expires_at',
        'last_evaluated_at',
        'last_triggered_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertSubscriptionType::class,
            'config' => 'array',
            'last_observed_state' => 'array',
            'last_condition' => 'boolean',
            'is_active' => 'boolean',
            'cooldown_minutes' => 'integer',
            'expires_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
            'last_triggered_at' => 'datetime',
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
            ->where(fn (Builder $expiry): Builder => $expiry
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
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
