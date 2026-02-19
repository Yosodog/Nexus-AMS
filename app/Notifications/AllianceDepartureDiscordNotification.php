<?php

namespace App\Notifications;

use App\Models\Alliance;
use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AllianceDepartureDiscordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Nation $nation,
        private readonly ?Alliance $previousAlliance,
        private readonly string $channelId
    ) {}

    /**
     * @return array<int, class-string<DiscordQueueChannel>>
     */
    public function via(object $notifiable): array
    {
        return [DiscordQueueChannel::class];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toDiscordBot(object $notifiable): array
    {
        return [
            'action' => 'ALLIANCE_DEPARTURE',
            'channel_id' => $this->channelId,
            'payload' => [
                'channel_id' => $this->channelId,
                'left_at' => now()->toIso8601String(),
                'nation' => $this->formatNation($this->nation),
                'previous_alliance' => $this->formatAlliance($this->previousAlliance),
                'new_alliance' => $this->formatAlliance($this->nation->alliance),
            ],
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function formatNation(Nation $nation): array
    {
        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'links' => [
                'nation' => $this->nationUrl($nation->id),
            ],
        ];
    }

    protected function formatAlliance(?Alliance $alliance): ?array
    {
        if (! $alliance) {
            return null;
        }

        return [
            'id' => $alliance->id,
            'name' => $alliance->name,
            'link' => $this->allianceUrl($alliance->id),
        ];
    }

    protected function nationUrl(int $nationId): string
    {
        return sprintf('https://politicsandwar.com/nation/id=%d', $nationId);
    }

    protected function allianceUrl(int $allianceId): string
    {
        return sprintf('https://politicsandwar.com/alliance/id=%d', $allianceId);
    }
}
