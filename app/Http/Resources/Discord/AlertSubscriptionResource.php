<?php

namespace App\Http\Resources\Discord;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Models\AlertSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin AlertSubscription */
class AlertSubscriptionResource extends JsonResource
{
    /** @param list<string> $eventKeys */
    public function __construct(
        AlertSubscription $resource,
        private readonly bool $globalDiscordEnabled,
        private readonly bool $discordLinked,
        private readonly array $eventKeys,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $config = $this->config;
        $condition = $this->type->value === 'market'
            ? sprintf(
                '%s %s %s',
                ucfirst((string) ($config['resource'] ?? 'resource')),
                (string) ($config['direction'] ?? 'crosses'),
                number_format((float) ($config['threshold'] ?? 0), 2),
            )
            : collect($this->eventKeys)
                ->map(fn (string $key): string => AlertActivityResource::eventLabel($key))
                ->join(', ');

        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'target_id' => $this->target_id ?? ($config['target_id'] ?? null),
            'filter' => $this->safeFilter($config),
            'events' => collect($this->eventKeys)->map(fn (string $key): array => [
                'key' => $key,
                'label' => AlertActivityResource::eventLabel($key),
            ])->values()->all(),
            'condition' => $condition,
            'active' => $this->is_active,
            'status' => $this->status?->value ?? ($this->is_active ? 'active' : 'paused'),
            'status_reason' => $this->status_reason,
            'cooldown_minutes' => $this->cooldown_minutes,
            'rearm_percent' => (float) $this->rearm_percent,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_evaluated_at' => $this->last_evaluated_at?->toIso8601String(),
            'last_triggered_at' => $this->last_triggered_at?->toIso8601String(),
            'last_matched_at' => $this->last_triggered_at?->toIso8601String(),
            'delivery' => [
                'mode' => ($this->delivery_mode ?? AlertDeliveryMode::Immediate)->value,
                'discord_enabled' => $this->discord_enabled,
                'global_discord_enabled' => $this->globalDiscordEnabled,
                'health' => $this->deliveryHealth(),
                'last_failure_code' => $this->getAttribute('last_discord_reason'),
                'last_delivered_at' => $this->dateAttribute('last_discord_delivered_at'),
                'next_at' => $this->dateAttribute('next_discord_delivery_at'),
                'timezone' => $this->timezone,
            ],
            'deep_link_path' => route('user.alerts.index', absolute: false),
        ];
    }

    /** @param array<string, mixed> $config
     * @return array{target_id: int|null}|array{resource: string|null, direction: string|null, threshold: float|null}
     */
    private function safeFilter(array $config): array
    {
        if ($this->type->value === 'market') {
            $resource = $config['resource'] ?? null;
            $direction = $config['direction'] ?? null;
            $threshold = $config['threshold'] ?? null;

            return [
                'resource' => is_string($resource) && $resource !== '' ? $resource : null,
                'direction' => is_string($direction) && in_array($direction, ['above', 'below'], true)
                    ? $direction
                    : null,
                'threshold' => is_numeric($threshold) ? (float) $threshold : null,
            ];
        }

        $targetId = $this->target_id ?? ($config['target_id'] ?? null);

        return ['target_id' => is_numeric($targetId) ? (int) $targetId : null];
    }

    private function deliveryHealth(): string
    {
        if (! $this->discord_enabled) {
            return 'subscription_disabled';
        }

        if (! $this->globalDiscordEnabled) {
            return 'user_disabled';
        }

        if (! $this->discordLinked) {
            return 'recipient_unavailable';
        }

        return in_array($this->getAttribute('last_discord_status'), [
            AlertDeliveryStatus::Failed->value,
            AlertDeliveryStatus::Undeliverable->value,
            AlertDeliveryStatus::Quarantined->value,
        ], true) ? 'unhealthy' : 'healthy';
    }

    private function dateAttribute(string $key): ?string
    {
        $value = $this->getAttribute($key);

        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }
}
