<?php

namespace App\Notifications;

use App\Notifications\Channels\DiscordQueueChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InactivityDiscordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $channelId,
        private readonly string $message,
        private readonly ?string $discordUserId,
        private readonly array $payload = []
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, class-string<DiscordQueueChannel>>
     */
    public function via(object $notifiable): array
    {
        return [DiscordQueueChannel::class];
    }

    /**
     * @return array{
     *     action: string,
     *     channel_id: string,
     *     payload: array<string, mixed>
     * }
     */
    public function toDiscordBot(object $notifiable): array
    {
        return [
            'action' => 'INACTIVITY_ALERT',
            'channel_id' => $this->channelId,
            'payload' => array_merge([
                'channel_id' => $this->channelId,
                'message' => $this->message,
                'discord_user_id' => $this->discordUserId,
            ], $this->payload),
        ];
    }
}
