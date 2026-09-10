<?php

namespace App\Services\Alerts;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Enums\DiscordQueueAction;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use App\Models\AlertUserSetting;
use App\Services\Discord\DiscordConnectionResolutionException;
use App\Services\Discord\DiscordConnectionResolver;
use App\Services\Finance\DiscordResourceShortfallFulfillmentService;
use App\Services\SettingService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResourceShortfallAlertService
{
    public const DISCORD_CAPABILITY = 'alerts.resource-shortfall-actions.v1';

    public const COOLDOWN_HOURS = 48;

    public function __construct(
        private readonly AlertSubscriptionEligibilityService $eligibility,
        private readonly AlertUserSettingsService $userSettings,
        private readonly ResourceShortfallService $shortfalls,
        private readonly AlertOccurrenceRecorder $occurrences,
        private readonly DiscordConnectionResolver $connections,
        private readonly DiscordResourceShortfallFulfillmentService $fulfillments,
    ) {}

    public function relaySupportsAlerts(): bool
    {
        try {
            $connection = $this->connections->resolveForQueueProducer();

            return $connection->supports(self::DISCORD_CAPABILITY)
                && $connection->supportsQueueAction(DiscordQueueAction::AlertDeliveryV1->value);
        } catch (DiscordConnectionResolutionException) {
            return false;
        }
    }

    public function evaluate(AlertUserSetting $settings): ?AlertOccurrence
    {
        $user = $settings->user;
        if (! $settings->resource_shortfall_alerts_enabled
            || ! $settings->discord_enabled
            || ! SettingService::areDiscordPrivateNotificationsEnabled()
            || $user === null
            || ! $this->userSettings->isDiscordEnabled($user)) {
            return null;
        }

        $nation = $this->eligibility->eligibleNation($user);
        if ($nation === null || $this->isCoolingDownOrInFlight($user->id)) {
            return null;
        }

        $projection = $this->shortfalls->project($nation);
        if ($projection === null) {
            Log::debug('Resource shortfall alert evaluation skipped.', [
                'user_id' => $user->id,
                'nation_id' => $nation->id,
                'reason' => 'no_current_shortfall_or_stale_data',
            ]);

            return null;
        }

        $actionAvailable = false;
        if (! TransactionService::hasPendingTransaction((int) $nation->id)) {
            try {
                $actionAvailable = $this->fulfillments->buildOptions($user, $projection) !== [];
            } catch (Throwable $exception) {
                Log::warning('Resource shortfall action availability could not be evaluated.', [
                    'user_id' => $user->id,
                    'nation_id' => $nation->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $occurrence = $this->occurrences->record(
            eventKey: ResourceShortfallService::EVENT_KEY,
            sourceType: 'resource_shortfall_projection',
            sourceId: $nation->id,
            dedupeKey: hash('sha256', implode('|', [
                ResourceShortfallService::EVENT_KEY,
                (string) $user->id,
                $projection['snapshot_fingerprint'],
            ])),
            payload: [
                'nation_id' => $nation->id,
                'nation_name' => $nation->nation_name,
                'target_turns' => ResourceShortfallService::TARGET_TURNS,
                'calculated_at' => $projection['calculated_at']->utc()->toISOString(),
                'resource_snapshot_at' => $projection['resource_snapshot_at']->utc()->toISOString(),
                'shortfalls' => $projection['lines'],
                'action_available' => $actionAvailable,
            ],
            occurredAt: now(),
            observedAt: $projection['resource_snapshot_at'],
            allianceId: $nation->alliance_id,
            audienceUserId: $user->id,
            subjectType: 'nation',
            subjectId: $nation->id,
            sourceVersion: $projection['snapshot_fingerprint'],
            correlationKey: 'resource-shortfall:user:'.$user->id,
            deepLinkPath: '/user/accounts',
            discordEnabled: true,
            respectUserQuietHours: true,
        );

        Log::info('Resource shortfall alert recorded.', [
            'alert_occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'nation_id' => $nation->id,
            'resources' => collect($projection['lines'])->pluck('resource')->all(),
            'action_available' => $actionAvailable,
        ]);

        return $occurrence;
    }

    private function isCoolingDownOrInFlight(int $userId): bool
    {
        return AlertDelivery::query()
            ->where('recipient_user_id', $userId)
            ->where('destination_kind', AlertDestinationKind::DiscordDm->value)
            ->whereHas('occurrence', fn ($query) => $query
                ->where('event_key', ResourceShortfallService::EVENT_KEY))
            ->where(function ($query): void {
                $query->whereIn('status', [
                    AlertDeliveryStatus::Pending->value,
                    AlertDeliveryStatus::Scheduled->value,
                    AlertDeliveryStatus::Queued->value,
                ])->orWhere(function ($delivered): void {
                    $delivered
                        ->where('status', AlertDeliveryStatus::Delivered->value)
                        ->where('delivered_at', '>=', now()->subHours(self::COOLDOWN_HOURS));
                });
            })
            ->exists();
    }
}
