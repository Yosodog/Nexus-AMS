<?php

namespace App\Listeners;

use App\Enums\AlliancePositionEnum;
use App\Events\NationAllianceChanged;
use App\Models\Account;
use App\Models\Alliance;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Notifications\AllianceDepartureDiscordNotification;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Services\AllianceMembershipService;
use App\Services\Discord\DiscordQueueService;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendAllianceDepartureDiscordNotification implements ShouldQueue
{
    use InteractsWithQueue;

    private const ROLE_REMOVAL_ACTION = 'ALLIANCE_ROLE_REMOVAL';

    public function __construct(
        private readonly AllianceMembershipService $membershipService,
        private readonly DiscordQueueService $discordQueueService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(NationAllianceChanged $event): void
    {
        if (! $event->changedAlliance()) {
            return;
        }

        if (! $event->oldAllianceId
            || ! $this->membershipService->contains($event->oldAllianceId)
            || $this->membershipService->contains($event->newAllianceId)
            || $event->oldAlliancePosition === AlliancePositionEnum::APPLICANT->value
        ) {
            return;
        }

        $nation = $event->nation->loadMissing([
            'alliance',
            'accountProfile',
            'user.discordAccounts',
        ]);

        $this->cleanupNexusAccess($nation);
        $this->queueDiscordRoleRemoval($nation);

        $channelId = SettingService::getDiscordAllianceDepartureChannelId();

        if (! SettingService::isDiscordAllianceDepartureEnabled() || $channelId === '') {
            Log::notice('Alliance departure alert skipped: channel not configured', [
                'nation_id' => $event->nation->id,
                'old_alliance_id' => $event->oldAllianceId,
            ]);

            return;
        }

        $previousAlliance = Alliance::find($event->oldAllianceId);

        $lockAcquired = Cache::lock($this->departureLockKey($nation->id, $event->oldAllianceId), 30)->get(function () use (
            $nation,
            $event,
            $previousAlliance,
            $channelId
        ): bool {
            if ($this->hasRecentQueuedDeparture($nation->id, $event->oldAllianceId)) {
                Log::info('Alliance departure alert skipped due to recent queued job', [
                    'nation_id' => $nation->id,
                    'old_alliance_id' => $event->oldAllianceId,
                ]);

                return true;
            }

            try {
                Notification::route(DiscordQueueChannel::class, 'discord-bot')
                    ->notify(new AllianceDepartureDiscordNotification(
                        nation: $nation,
                        previousAlliance: $previousAlliance,
                        channelId: $channelId
                    ));
            } catch (Throwable $exception) {
                Log::error('Failed to queue alliance departure Discord alert', [
                    'nation_id' => $nation->id,
                    'old_alliance_id' => $event->oldAllianceId,
                    'message' => $exception->getMessage(),
                ]);
            }

            return true;
        });

        if (! $lockAcquired) {
            Log::info('Alliance departure alert skipped due to overlapping lock', [
                'nation_id' => $nation->id,
                'old_alliance_id' => $event->oldAllianceId,
                'lock_key' => $this->departureLockKey($nation->id, $event->oldAllianceId),
            ]);
        }
    }

    protected function hasRecentQueuedDeparture(int $nationId, int $oldAllianceId): bool
    {
        return DiscordQueue::query()
            ->where('action', 'ALLIANCE_DEPARTURE')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereJsonContains('payload->nation->id', $nationId)
            ->whereJsonContains('payload->previous_alliance->id', $oldAllianceId)
            ->exists();
    }

    protected function departureLockKey(int $nationId, int $oldAllianceId): string
    {
        return "discord:alliance-departure:{$nationId}:{$oldAllianceId}";
    }

    protected function cleanupNexusAccess(Nation $nation): void
    {
        DB::transaction(function () use ($nation) {
            Account::query()
                ->where('nation_id', $nation->id)
                ->where('frozen', false)
                ->update(['frozen' => true]);

            $user = $nation->user;

            if (! $user) {
                return;
            }

            $user->is_admin = false;
            $user->disabled = true;
            $user->save();

            $user->roles()->sync([]);
        });
    }

    protected function queueDiscordRoleRemoval(Nation $nation): void
    {
        $discordId = $this->resolveDiscordId($nation);

        if (! $discordId) {
            Log::info('Alliance departure role removal skipped: missing Discord ID', [
                'nation_id' => $nation->id,
                'user_id' => $nation->user?->id,
            ]);

            return;
        }

        $payload = [
            'discord_id' => $discordId,
            'nation_id' => $nation->id,
            'left_at' => now()->toIso8601String(),
        ];

        $this->discordQueueService->enqueue(self::ROLE_REMOVAL_ACTION, $payload);
    }

    protected function resolveDiscordId(Nation $nation): ?string
    {
        $userDiscordId = $nation->user?->activeDiscordAccount()?->discord_id;

        if (is_string($userDiscordId) && $userDiscordId !== '') {
            return $userDiscordId;
        }

        $accountDiscordId = $nation->accountProfile?->discord_id;

        if (is_string($accountDiscordId) && $accountDiscordId !== '') {
            return $accountDiscordId;
        }

        $nationDiscordId = $nation->discord_id;

        if (is_string($nationDiscordId) && $nationDiscordId !== '') {
            return $nationDiscordId;
        }

        return null;
    }
}
