<?php

namespace App\Listeners;

use App\Enums\AlliancePositionEnum;
use App\Enums\DiscordQueueStatus;
use App\Events\NationAllianceChanged;
use App\Models\Alliance;
use App\Models\DiscordQueue;
use App\Notifications\AllianceDepartureDiscordNotification;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Services\AllianceMembershipService;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendAllianceDepartureDiscordNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(private readonly AllianceMembershipService $membershipService) {}

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

        $channelId = SettingService::getDiscordAllianceDepartureChannelId();

        if (! SettingService::isDiscordAllianceDepartureEnabled() || $channelId === '') {
            Log::notice('Alliance departure alert skipped: channel not configured', [
                'nation_id' => $event->nation->id,
                'old_alliance_id' => $event->oldAllianceId,
            ]);

            return;
        }

        $previousAlliance = Alliance::find($event->oldAllianceId);
        $nation = $event->nation->loadMissing('alliance');

        if ($this->hasRecentQueuedDeparture($nation->id)) {
            Log::info('Alliance departure alert skipped due to recent queued job', [
                'nation_id' => $nation->id,
                'old_alliance_id' => $event->oldAllianceId,
            ]);

            return;
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
    }

    protected function hasRecentQueuedDeparture(int $nationId): bool
    {
        return DiscordQueue::query()
            ->where('action', 'ALLIANCE_DEPARTURE')
            ->where('status', DiscordQueueStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereJsonContains('payload->nation->id', $nationId)
            ->exists();
    }
}
