<?php

namespace App\Notifications;

use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class GrowthCircleAbuseSuspendedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $channelId,
        private readonly Nation $nation,
    ) {}

    /**
     * @return array<int, class-string<DiscordQueueChannel>>
     */
    public function via(object $notifiable): array
    {
        return [DiscordQueueChannel::class];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDiscordBot(object $notifiable): array
    {
        return [
            'action' => 'GROWTH_CIRCLE_ABUSE_ALERT',
            'channel_id' => $this->channelId,
            'payload' => [
                'channel_id' => $this->channelId,
                'event_type' => 'growth_circle_abuse_suspension',
                'detected_at' => now()->toIso8601String(),
                'nation' => [
                    'id' => $this->nation->id,
                    'nation_name' => $this->nation->nation_name,
                    'leader_name' => $this->nation->leader_name,
                    'num_cities' => $this->nation->num_cities,
                ],
            ],
        ];
    }
}
