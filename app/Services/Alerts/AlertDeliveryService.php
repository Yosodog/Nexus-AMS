<?php

namespace App\Services\Alerts;

use App\Enums\AlertBatchStatus;
use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Enums\DiscordQueueAction;
use App\Enums\DiscordQueueLane;
use App\Models\AlertDelivery;
use App\Models\AlertDeliveryBatch;
use App\Models\AlertOccurrence;
use App\Models\AlertRoute;
use App\Models\AlertSubscription;
use App\Models\User;
use App\Services\Discord\DiscordQueueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AlertDeliveryService
{
    public function __construct(
        private readonly AlertEventCatalog $catalog,
        private readonly AlertDeliveryPolicy $policy,
        private readonly DiscordQueueService $discordQueue,
        private readonly AlertUserSettingsService $userSettings,
    ) {}

    /** @return Collection<int, AlertDelivery> */
    public function createForOccurrence(
        AlertOccurrence $occurrence,
        ?AlertSubscription $subscription = null,
        bool $discordEnabled = false,
        bool $forceImmediate = false,
    ): Collection {
        $deliveries = collect();

        if ($occurrence->audience_user_id !== null) {
            $recipient = User::query()->find($occurrence->audience_user_id);
            if ($recipient === null) {
                return $deliveries;
            }

            $deliveries->push($this->createWebDelivery($occurrence, $recipient, $subscription));
            if ($discordEnabled) {
                $suppressionReason = $this->memberDiscordSuppressionReason($recipient, $subscription);
                $deliveries->push($suppressionReason === null
                    ? $this->createMemberDiscordDelivery($occurrence, $recipient, $subscription, $forceImmediate)
                    : $this->createMemberDiscordSuppression($occurrence, $recipient, $subscription, $suppressionReason));
            }

            return $deliveries;
        }

        $routes = AlertRoute::query()
            ->active()
            ->where('event_key', $occurrence->event_key)
            ->where(function (Builder $scope) use ($occurrence): void {
                $scope->whereNull('alliance_id');
                if ($occurrence->alliance_id !== null) {
                    $scope->orWhere('alliance_id', $occurrence->alliance_id);
                }
            })
            ->with(['destination', 'createdBy.roles.permissions'])
            ->get();

        foreach ($routes as $route) {
            if (! $this->routeIsAuthorized($route, $occurrence)) {
                $deliveries->push($this->createUnauthorizedRouteDelivery($occurrence, $route));

                continue;
            }

            if (! $this->routeMatches($route, $occurrence)) {
                continue;
            }

            $deliveries->push($this->createRouteWebDelivery($occurrence, $route));
            if ($route->destination->kind->isDiscord()) {
                $deliveries->push($this->createRouteDiscordDelivery($occurrence, $route));
            }
        }

        return $deliveries;
    }

    private function routeIsAuthorized(AlertRoute $route, AlertOccurrence $occurrence): bool
    {
        $permission = $this->catalog->get($occurrence->event_key)->requiredPermission;
        if ($permission === null) {
            return true;
        }

        $creator = $route->createdBy;

        return $creator !== null
            && ! $creator->disabled
            && $creator->hasPermission($permission);
    }

    private function createUnauthorizedRouteDelivery(
        AlertOccurrence $occurrence,
        AlertRoute $route,
    ): AlertDelivery {
        return AlertDelivery::query()->firstOrCreate(
            ['match_key' => $this->matchKey($occurrence, null, $route->id, 'authorization:route:'.$route->id)],
            [
                'alert_occurrence_id' => $occurrence->id,
                'alert_route_id' => $route->id,
                'alert_destination_id' => $route->alert_destination_id,
                'destination_kind' => $route->destination->kind,
                'status' => AlertDeliveryStatus::Quarantined,
                'reason_code' => 'route_permission_missing',
                'failed_at' => now(),
            ],
        );
    }

    private function createWebDelivery(
        AlertOccurrence $occurrence,
        User $recipient,
        ?AlertSubscription $subscription,
    ): AlertDelivery {
        return AlertDelivery::query()->firstOrCreate(
            ['match_key' => $this->matchKey($occurrence, $subscription?->id, null, 'web:user:'.$recipient->id)],
            [
                'alert_occurrence_id' => $occurrence->id,
                'alert_subscription_id' => $subscription?->id,
                'recipient_user_id' => $recipient->id,
                'destination_kind' => AlertDestinationKind::Web,
                'status' => AlertDeliveryStatus::Delivered,
                'delivered_at' => now(),
            ],
        );
    }

    private function createRouteWebDelivery(AlertOccurrence $occurrence, AlertRoute $route): AlertDelivery
    {
        return AlertDelivery::query()->firstOrCreate(
            ['match_key' => $this->matchKey($occurrence, null, $route->id, 'web:route:'.$route->id)],
            [
                'alert_occurrence_id' => $occurrence->id,
                'alert_route_id' => $route->id,
                'destination_kind' => AlertDestinationKind::Web,
                'status' => AlertDeliveryStatus::Delivered,
                'delivered_at' => now(),
            ],
        );
    }

    private function createMemberDiscordDelivery(
        AlertOccurrence $occurrence,
        User $recipient,
        ?AlertSubscription $subscription,
        bool $forceImmediate,
    ): AlertDelivery {
        $discordId = $recipient->activeDiscordAccount()?->discord_id;
        $matchKey = $this->matchKey($occurrence, $subscription?->id, null, 'discord-dm:user:'.$recipient->id);

        if (! is_string($discordId) || $discordId === '') {
            return AlertDelivery::query()->firstOrCreate(
                ['match_key' => $matchKey],
                [
                    'alert_occurrence_id' => $occurrence->id,
                    'alert_subscription_id' => $subscription?->id,
                    'recipient_user_id' => $recipient->id,
                    'destination_kind' => AlertDestinationKind::DiscordDm,
                    'status' => AlertDeliveryStatus::Undeliverable,
                    'reason_code' => 'recipient_unavailable',
                    'failed_at' => now(),
                ],
            );
        }

        $scheduledAt = $forceImmediate || $subscription === null
            ? null
            : $this->policy->scheduledAtForSubscription($subscription, $recipient);

        return $this->createDiscordDelivery(
            occurrence: $occurrence,
            matchKey: $matchKey,
            destinationKind: AlertDestinationKind::DiscordDm,
            destination: [
                'type' => 'dm',
                'discord_user_id' => $discordId,
            ],
            scheduledAt: $scheduledAt,
            deliveryMode: $forceImmediate
                ? AlertDeliveryMode::Immediate
                : ($subscription?->delivery_mode ?? AlertDeliveryMode::Immediate),
            subscriptionId: $subscription?->id,
            recipientUserId: $recipient->id,
        );
    }

    private function createMemberDiscordSuppression(
        AlertOccurrence $occurrence,
        User $recipient,
        ?AlertSubscription $subscription,
        string $reasonCode,
    ): AlertDelivery {
        return AlertDelivery::query()->firstOrCreate(
            ['match_key' => $this->matchKey($occurrence, $subscription?->id, null, 'discord-dm:user:'.$recipient->id)],
            [
                'alert_occurrence_id' => $occurrence->id,
                'alert_subscription_id' => $subscription?->id,
                'recipient_user_id' => $recipient->id,
                'destination_kind' => AlertDestinationKind::DiscordDm,
                'delivery_mode' => AlertDeliveryMode::Immediate,
                'status' => AlertDeliveryStatus::Suppressed,
                'reason_code' => $reasonCode,
            ],
        );
    }

    private function memberDiscordSuppressionReason(
        User $recipient,
        ?AlertSubscription $subscription,
    ): ?string {
        if ($subscription !== null && ! $subscription->discord_enabled) {
            return 'subscription_discord_disabled';
        }

        if (! $this->userSettings->isDiscordEnabled($recipient)) {
            return 'user_discord_disabled';
        }

        return null;
    }

    private function createRouteDiscordDelivery(AlertOccurrence $occurrence, AlertRoute $route): AlertDelivery
    {
        $destination = $route->destination;
        $deliveryMode = AlertDeliveryMode::tryFrom((string) data_get($route->delivery_policy, 'mode'))
            ?? AlertDeliveryMode::Immediate;

        return $this->createDiscordDelivery(
            occurrence: $occurrence,
            matchKey: $this->matchKey($occurrence, null, $route->id, 'destination:'.$destination->id),
            destinationKind: $destination->kind,
            destination: [
                'type' => 'channel',
                'guild_id' => $destination->guild_id,
                'channel_id' => $destination->channel_id,
                'allowed_role_ids' => $destination->mention_role_ids ?? [],
            ],
            scheduledAt: $this->policy->scheduledAtForRoute(
                $route,
                $this->catalog->get($occurrence->event_key)->mayBypassQuietHours,
            ),
            deliveryMode: $deliveryMode,
            routeId: $route->id,
            destinationId: $destination->id,
        );
    }

    /** @param array<string, mixed> $destination */
    private function createDiscordDelivery(
        AlertOccurrence $occurrence,
        string $matchKey,
        AlertDestinationKind $destinationKind,
        array $destination,
        ?\DateTimeInterface $scheduledAt,
        AlertDeliveryMode $deliveryMode = AlertDeliveryMode::Immediate,
        ?int $subscriptionId = null,
        ?int $routeId = null,
        ?int $destinationId = null,
        ?int $recipientUserId = null,
    ): AlertDelivery {
        $definition = $this->catalog->get($occurrence->event_key);
        if (! $definition->allows($destinationKind)) {
            return AlertDelivery::query()->firstOrCreate(
                ['match_key' => $matchKey],
                [
                    'alert_occurrence_id' => $occurrence->id,
                    'alert_subscription_id' => $subscriptionId,
                    'alert_route_id' => $routeId,
                    'alert_destination_id' => $destinationId,
                    'recipient_user_id' => $recipientUserId,
                    'destination_kind' => $destinationKind,
                    'delivery_mode' => $deliveryMode,
                    'status' => AlertDeliveryStatus::Quarantined,
                    'reason_code' => 'destination_not_allowed',
                    'destination_snapshot' => $destination,
                    'failed_at' => now(),
                ],
            );
        }

        $initialStatus = $scheduledAt === null
            ? AlertDeliveryStatus::Pending
            : AlertDeliveryStatus::Scheduled;
        $delivery = AlertDelivery::query()->firstOrCreate(
            ['match_key' => $matchKey],
            [
                'alert_occurrence_id' => $occurrence->id,
                'alert_subscription_id' => $subscriptionId,
                'alert_route_id' => $routeId,
                'alert_destination_id' => $destinationId,
                'recipient_user_id' => $recipientUserId,
                'destination_kind' => $destinationKind,
                'delivery_mode' => $deliveryMode,
                'status' => $initialStatus,
                'destination_snapshot' => $destination,
                'scheduled_at' => $scheduledAt,
            ],
        );

        if ($delivery->wasRecentlyCreated && $scheduledAt === null) {
            $this->queueDelivery($delivery, $occurrence, $destination);
        }

        return $delivery->refresh();
    }

    /** @param array<string, mixed> $destination */
    public function queueDelivery(
        AlertDelivery $delivery,
        AlertOccurrence $occurrence,
        array $destination,
    ): AlertDeliveryBatch {
        $definition = $this->catalog->get($occurrence->event_key);
        $batch = AlertDeliveryBatch::query()->firstOrCreate(
            ['dedupe_key' => 'alert-delivery:'.$delivery->match_key],
            [
                'alert_destination_id' => $delivery->alert_destination_id,
                'recipient_user_id' => $delivery->recipient_user_id,
                'destination_kind' => $delivery->destination_kind,
                'status' => AlertBatchStatus::Pending,
                'template_key' => $definition->templateKey,
                'schema_version' => $definition->schemaVersion,
                'destination_snapshot' => $destination,
                'is_test' => $occurrence->is_test,
            ],
        );

        $delivery->forceFill(['alert_delivery_batch_id' => $batch->id])->save();
        if (! $batch->wasRecentlyCreated && $batch->discord_queue_id !== null) {
            return $batch;
        }

        try {
            $queue = $this->discordQueue->enqueue(
                action: DiscordQueueAction::AlertDeliveryV1,
                payload: [
                    'contract_version' => 1,
                    'delivery_id' => (string) $delivery->id,
                    'batch_id' => (string) $batch->id,
                    'occurrence_id' => (string) $occurrence->id,
                    'event_key' => $occurrence->event_key,
                    'schema_version' => $definition->schemaVersion,
                    'template_key' => $definition->templateKey,
                    'destination' => Arr::except($destination, ['allowed_role_ids']),
                    'allowed_role_ids' => $destination['allowed_role_ids'] ?? [],
                    'data' => $occurrence->payload,
                    'occurred_at' => $occurrence->occurred_at->toIso8601String(),
                    'observed_at' => $occurrence->observed_at?->toIso8601String(),
                    'deep_link_path' => $occurrence->deep_link_path ?? '/alerts/activity',
                    'severity' => $occurrence->severity->value,
                    'priority' => (string) $occurrence->severity->priority(),
                    'is_test' => $occurrence->is_test,
                ],
                dedupeKey: 'alert-delivery-batch:'.$batch->id,
                lane: DiscordQueueLane::Alerts,
                priority: $occurrence->severity->priority(),
                alertDeliveryBatchId: $batch->id,
            );
        } catch (Throwable $exception) {
            $this->markEnqueueFailure($batch, collect([$delivery]), $exception);

            return $batch->refresh();
        }

        $batch->forceFill([
            'status' => AlertBatchStatus::Queued,
            'discord_queue_id' => $queue->id,
            'queued_at' => now(),
        ])->save();
        $delivery->forceFill([
            'status' => AlertDeliveryStatus::Queued,
            'queued_at' => now(),
        ])->save();

        return $batch->refresh();
    }

    /**
     * @param  Collection<int, AlertDelivery>  $deliveries
     * @param  array<string, mixed>  $destination
     */
    public function queueDigest(Collection $deliveries, array $destination): ?AlertDeliveryBatch
    {
        $deliveries = $deliveries
            ->filter(fn (AlertDelivery $delivery): bool => $delivery->status === AlertDeliveryStatus::Scheduled)
            ->sortBy('id')
            ->values();
        if ($deliveries->isEmpty()) {
            return null;
        }

        $first = $deliveries->first();
        $dedupeKey = 'alert-digest:'.hash('sha256', $deliveries->pluck('match_key')->implode('|'));
        $batch = AlertDeliveryBatch::query()->firstOrCreate(
            ['dedupe_key' => $dedupeKey],
            [
                'alert_destination_id' => $first->alert_destination_id,
                'recipient_user_id' => $first->recipient_user_id,
                'destination_kind' => $first->destination_kind,
                'status' => AlertBatchStatus::Pending,
                'template_key' => 'digest.v1',
                'schema_version' => 1,
                'destination_snapshot' => $destination,
                'scheduled_at' => $deliveries->min('scheduled_at'),
                'is_test' => $deliveries->contains(fn (AlertDelivery $delivery): bool => $delivery->occurrence->is_test),
            ],
        );

        if (! $batch->wasRecentlyCreated && $batch->discord_queue_id !== null) {
            return $batch;
        }

        $items = $deliveries->take(20)->map(function (AlertDelivery $delivery): array {
            $occurrence = $delivery->occurrence;

            return [
                'event_key' => $occurrence->event_key,
                'title' => (string) ($occurrence->payload['label'] ?? Str::headline($occurrence->event_key)),
                'description' => $this->digestDescription($occurrence),
                'occurred_at' => $occurrence->occurred_at->toIso8601String(),
                'deep_link_path' => $occurrence->deep_link_path ?? '/alerts/activity',
            ];
        })->all();
        $firstOccurrence = $first->occurrence;

        try {
            $queue = $this->discordQueue->enqueue(
                action: DiscordQueueAction::AlertDeliveryV1,
                payload: [
                    'contract_version' => 1,
                    'delivery_id' => 'batch:'.$batch->id,
                    'batch_id' => (string) $batch->id,
                    'occurrence_id' => (string) $firstOccurrence->id,
                    'event_key' => $firstOccurrence->event_key,
                    'schema_version' => 1,
                    'template_key' => 'digest.v1',
                    'destination' => Arr::except($destination, ['allowed_role_ids']),
                    'allowed_role_ids' => $destination['allowed_role_ids'] ?? [],
                    'data' => [
                        'title' => 'Nexus Alert Digest',
                        'description' => 'Alerts collected during your configured delivery window.',
                        'items' => $items,
                        'count' => $deliveries->count(),
                        'remaining_count' => max(0, $deliveries->count() - count($items)),
                        'remaining_items_path' => '/alerts/activity',
                    ],
                    'occurred_at' => $deliveries->min(fn (AlertDelivery $delivery): string => $delivery->occurrence->occurred_at->toIso8601String()),
                    'observed_at' => now()->toIso8601String(),
                    'deep_link_path' => '/alerts/activity',
                    'severity' => $firstOccurrence->severity->value,
                    'priority' => (string) $deliveries->max(fn (AlertDelivery $delivery): int => $delivery->occurrence->severity->priority()),
                    'is_test' => $batch->is_test,
                ],
                dedupeKey: 'alert-delivery-batch:'.$batch->id,
                lane: DiscordQueueLane::Digests,
                priority: $deliveries->max(fn (AlertDelivery $delivery): int => $delivery->occurrence->severity->priority()),
                alertDeliveryBatchId: $batch->id,
            );
        } catch (Throwable $exception) {
            $this->markEnqueueFailure($batch, $deliveries, $exception);

            return $batch->refresh();
        }

        DB::transaction(function () use ($batch, $deliveries, $queue): void {
            AlertDelivery::query()->whereKey($deliveries->pluck('id'))->update([
                'alert_delivery_batch_id' => $batch->id,
                'status' => AlertDeliveryStatus::Queued->value,
                'queued_at' => now(),
                'updated_at' => now(),
            ]);
            $batch->forceFill([
                'status' => AlertBatchStatus::Queued,
                'discord_queue_id' => $queue->id,
                'queued_at' => now(),
            ])->save();
        });

        return $batch->refresh();
    }

    private function digestDescription(AlertOccurrence $occurrence): string
    {
        $payload = collect(Arr::except($occurrence->payload, ['label']))
            ->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '')
            ->take(4)
            ->map(fn (mixed $value, string $key): string => Str::headline($key).': '.(string) $value)
            ->implode(' · ');

        return Str::limit($payload !== '' ? $payload : Str::headline($occurrence->event_key), 500, '');
    }

    /** @param Collection<int, AlertDelivery> $deliveries */
    private function markEnqueueFailure(
        AlertDeliveryBatch $batch,
        Collection $deliveries,
        Throwable $exception,
    ): void {
        Log::error('Alert delivery could not be added to the Discord queue.', [
            'batch_id' => $batch->id,
            'delivery_ids' => $deliveries->pluck('id')->all(),
            'exception' => $exception,
        ]);

        $batch->forceFill([
            'status' => AlertBatchStatus::Failed,
            'failure_code' => 'queue_enqueue_failed',
            'failure_message' => (string) str($exception->getMessage())->limit(2000, ''),
            'failed_at' => now(),
        ])->save();

        AlertDelivery::query()->whereKey($deliveries->pluck('id'))->update([
            'status' => AlertDeliveryStatus::Failed->value,
            'reason_code' => 'queue_enqueue_failed',
            'failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function routeMatches(AlertRoute $route, AlertOccurrence $occurrence): bool
    {
        if ($occurrence->severity->rank() < $route->minimum_severity->rank()) {
            return false;
        }

        $filters = $route->filter_config ?? [];
        if (isset($filters['nation_ids']) && ! in_array((int) $occurrence->subject_id, array_map('intval', $filters['nation_ids']), true)) {
            return false;
        }

        if (isset($filters['alliance_ids']) && ! in_array((int) $occurrence->alliance_id, array_map('intval', $filters['alliance_ids']), true)) {
            return false;
        }

        return true;
    }

    private function matchKey(
        AlertOccurrence $occurrence,
        ?int $subscriptionId,
        ?int $routeId,
        string $destination,
    ): string {
        return hash('sha256', implode('|', [
            $occurrence->id,
            $subscriptionId ?? 'none',
            $routeId ?? 'none',
            Str::lower($destination),
        ]));
    }
}
