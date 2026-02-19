<?php

namespace App\Services;

use App\Enums\InactivityAction;
use App\GraphQL\Models\Nation as GraphQLNation;
use App\Models\InactivityEvent;
use App\Models\Nation;
use App\Models\NationAccount;
use App\Services\Inactivity\Actions\AutoEnrollDirectDepositAction;
use App\Services\Inactivity\Actions\SendDiscordNotificationAction;
use App\Services\Inactivity\Actions\SendInGameMessageAction;
use App\Services\Inactivity\InactivityActionContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InactivityModeService
{
    private const ACTION_ORDER = [
        InactivityAction::AutoEnrollDirectDeposit,
        InactivityAction::SendInGameMessage,
        InactivityAction::SendDiscordNotification,
    ];

    /**
     * @var array<string, object>
     */
    private array $handlers;

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        AutoEnrollDirectDepositAction $autoEnrollDirectDepositAction,
        SendInGameMessageAction $sendInGameMessageAction,
        SendDiscordNotificationAction $sendDiscordNotificationAction
    ) {
        $this->handlers = [
            InactivityAction::AutoEnrollDirectDeposit->value => $autoEnrollDirectDepositAction,
            InactivityAction::SendInGameMessage->value => $sendInGameMessageAction,
            InactivityAction::SendDiscordNotification->value => $sendDiscordNotificationAction,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function evaluate(): array
    {
        $now = now();
        $thresholdHours = SettingService::getInactivityThresholdHours();
        $enabled = SettingService::isInactivityModeEnabled();
        $cooldownHours = SettingService::getInactivityCooldownHours();
        $actions = $this->resolveConfiguredActions();

        $allianceIds = $this->membershipService->getAllianceIds()
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if (empty($allianceIds)) {
            return ['processed' => 0];
        }

        try {
            $nations = NationQueryService::getMultipleNations([
                'alliance_id' => $allianceIds,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('InactivityMode: failed to fetch alliance nations', [
                'message' => $exception->getMessage(),
            ]);

            return ['processed' => 0];
        }

        $nationIds = collect($nations)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (empty($nationIds)) {
            return ['processed' => 0];
        }

        $localNations = Nation::query()
            ->whereIn('id', $nationIds)
            ->with(['accountProfile', 'directDepositEnrollment', 'user.discordAccounts'])
            ->get()
            ->keyBy('id');

        $openEvents = InactivityEvent::query()
            ->whereNull('episode_ended_at')
            ->whereIn('nation_id', $nationIds)
            ->get()
            ->keyBy('nation_id');

        $processed = 0;

        foreach ($nations as $remoteNation) {
            if (! $remoteNation instanceof GraphQLNation || ! isset($remoteNation->id)) {
                continue;
            }

            if ($this->shouldSkipRemoteNation($remoteNation)) {
                continue;
            }

            $localNation = $localNations->get((int) $remoteNation->id);

            if (! $localNation) {
                continue;
            }

            $accountProfile = $localNation->accountProfile;

            $lastActiveAt = $this->parseLastActive($remoteNation->last_active ?? null);
            $accountProfile = $this->syncLastActive($localNation, $accountProfile, $lastActiveAt);

            if (! $lastActiveAt || ! $enabled) {
                $processed++;

                continue;
            }

            $inactiveNow = $lastActiveAt->lt($now->copy()->subHours($thresholdHours));

            if (! $openEvents->has($localNation->id) && $inactiveNow) {
                $event = $this->handleBecameInactive(
                    $localNation,
                    $lastActiveAt,
                    $now,
                    $actions,
                    $thresholdHours,
                    $cooldownHours
                );

                if ($event) {
                    $openEvents->put($localNation->id, $event);
                }
                $processed++;

                continue;
            }

            if ($openEvents->has($localNation->id) && ! $inactiveNow) {
                $this->handleBecameActive($openEvents->get($localNation->id), $now);
                $openEvents->forget($localNation->id);

                $processed++;

                continue;
            }

            if ($openEvents->has($localNation->id) && $inactiveNow) {
                $this->handleStillInactive(
                    $localNation,
                    $openEvents->get($localNation->id),
                    $lastActiveAt,
                    $now,
                    $actions,
                    $thresholdHours,
                    $cooldownHours
                );
                $processed++;
            }
        }

        return ['processed' => $processed];
    }

    public function recordDirectDepositOptOut(Nation $nation): void
    {
        $event = $this->resolveOpenEvent($nation);

        if (! $event) {
            return;
        }

        DB::transaction(function () use ($event): void {
            if (! $event->dd_opted_out_at) {
                $event->forceFill(['dd_opted_out_at' => now()])->save();
            }

            if (is_null($event->episode_ended_at)) {
                $event->forceFill(['episode_ended_at' => now()])->save();
            }
        });
    }

    protected function handleBecameInactive(
        Nation $nation,
        CarbonInterface $lastActiveAt,
        CarbonInterface $now,
        array $actions,
        int $thresholdHours,
        int $cooldownHours
    ): ?InactivityEvent {
        $event = DB::transaction(function () use ($nation, $lastActiveAt, $now, $actions, $thresholdHours, $cooldownHours) {
            $existing = InactivityEvent::query()
                ->where('nation_id', $nation->id)
                ->whereNull('episode_ended_at')
                ->latest('episode_started_at')
                ->first();

            if ($existing) {
                $existing->forceFill(['episode_ended_at' => $now])->save();
            }

            $event = InactivityEvent::create([
                'nation_id' => $nation->id,
                'episode_started_at' => $now,
                'detected_inactive_at' => $now,
                'actions_config_snapshot' => $actions,
                'meta' => [
                    'last_active' => $lastActiveAt->toIso8601String(),
                    'threshold_hours' => $thresholdHours,
                    'cooldown_hours' => $cooldownHours,
                ],
            ]);

            return $event;
        });

        $context = $this->buildActionContext($nation, $lastActiveAt, $now, $thresholdHours);
        $notificationSent = $this->executeActions($nation, $event, $context, $actions);

        if ($notificationSent) {
            $event->forceFill([
                'last_notified_at' => $now,
                'last_notification_type' => 'initial',
            ])->save();
        }

        return $event;
    }

    protected function handleBecameActive(InactivityEvent $event, CarbonInterface $now): void
    {
        DB::transaction(function () use ($event, $now): void {
            $event->forceFill(['episode_ended_at' => $now])->save();
        });
    }

    protected function handleStillInactive(
        Nation $nation,
        InactivityEvent $event,
        CarbonInterface $lastActiveAt,
        CarbonInterface $now,
        array $actions,
        int $thresholdHours,
        int $cooldownHours
    ): void {
        if (! $event || ! $this->shouldRenotify($event, $now, $cooldownHours)) {
            return;
        }

        $context = $this->buildActionContext($nation, $lastActiveAt, $now, $thresholdHours);
        $notificationSent = $this->executeActions($nation, $event, $context, $actions);

        if ($notificationSent) {
            $event->forceFill([
                'last_notified_at' => $now,
                'last_notification_type' => 'repeat',
            ])->save();
        }
    }

    protected function resolveOpenEvent(Nation $nation): ?InactivityEvent
    {
        return InactivityEvent::query()
            ->where('nation_id', $nation->id)
            ->whereNull('episode_ended_at')
            ->latest('episode_started_at')
            ->first();
    }

    protected function buildActionContext(
        Nation $nation,
        CarbonInterface $lastActiveAt,
        CarbonInterface $now,
        int $thresholdHours
    ): InactivityActionContext {
        $directDepositEnabled = SettingService::isDirectDepositEnabled();
        $enrolledInDd = $nation->relationLoaded('directDepositEnrollment')
            ? (bool) $nation->directDepositEnrollment
            : $nation->directDepositEnrollment()->exists();

        return new InactivityActionContext(
            now: $now,
            lastActiveAt: $lastActiveAt,
            thresholdHours: $thresholdHours,
            accountsUrl: route('accounts'),
            directDepositEnabled: $directDepositEnabled,
            wasDirectDepositEnrolled: $enrolledInDd,
            autoEnrolledDirectDeposit: false
        );
    }

    protected function executeActions(
        Nation $nation,
        InactivityEvent $event,
        InactivityActionContext $context,
        array $actions
    ): bool {
        $notificationSent = false;

        foreach ($actions as $actionValue) {
            $handler = $this->handlers[$actionValue] ?? null;

            if (! $handler) {
                continue;
            }

            $result = $handler->handle($nation, $event, $context);

            if ($result->notificationSent) {
                $notificationSent = true;
            }
        }

        return $notificationSent;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveConfiguredActions(): array
    {
        $configured = SettingService::getInactivityActions();

        $actions = collect($configured)
            ->map(fn ($value) => InactivityAction::tryFrom($value))
            ->filter()
            ->values();

        return collect(self::ACTION_ORDER)
            ->filter(fn (InactivityAction $action) => $actions->contains($action))
            ->map(fn (InactivityAction $action) => $action->value)
            ->values()
            ->all();
    }

    protected function shouldSkipRemoteNation(GraphQLNation $nation): bool
    {
        $alliancePosition = strtolower((string) ($nation->alliance_position ?? ''));

        if ($alliancePosition === 'applicant') {
            return true;
        }

        $vacationTurns = (int) ($nation->vacation_mode_turns ?? 0);

        return $vacationTurns > 0;
    }

    protected function parseLastActive(?string $lastActive): ?CarbonInterface
    {
        if (! $lastActive) {
            return null;
        }

        try {
            return Carbon::parse($lastActive);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function syncLastActive(
        Nation $nation,
        ?NationAccount $accountProfile,
        ?CarbonInterface $lastActiveAt
    ): ?NationAccount {
        if (! $lastActiveAt) {
            return $accountProfile;
        }

        if (! $accountProfile) {
            return NationAccount::create([
                'nation_id' => $nation->id,
                'last_active' => $lastActiveAt,
            ]);
        }

        $accountProfile->forceFill([
            'last_active' => $lastActiveAt,
        ]);

        if ($accountProfile->isDirty(['last_active'])) {
            $accountProfile->save();
        }

        return $accountProfile;
    }

    protected function shouldRenotify(InactivityEvent $event, CarbonInterface $now, int $cooldownHours): bool
    {
        if (! SettingService::isInactivityRepeatNotificationsEnabled()) {
            return false;
        }

        if (! $event->last_notified_at) {
            return false;
        }

        return $event->last_notified_at->lte($now->copy()->subHours($cooldownHours));
    }
}
