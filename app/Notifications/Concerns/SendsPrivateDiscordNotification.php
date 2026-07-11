<?php

namespace App\Notifications\Concerns;

use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Services\Discord\PrivateNotificationService;
use Illuminate\Support\Str;

trait SendsPrivateDiscordNotification
{
    /** @return array<int, string|class-string<DiscordQueueChannel>> */
    protected function pnwAndPrivateDiscordChannels(object $notifiable, string $category): array
    {
        $channels = ['pnw'];

        if (app(PrivateNotificationService::class)->canSendToNation($notifiable, $category)) {
            $channels[] = DiscordQueueChannel::class;
        }

        return $channels;
    }

    /**
     * @param  array{type:string,id:int|string,label?:string}  $subject
     * @param  array<string, mixed>  $summary
     * @return array{action:string,dedupe_key:string,payload:array<string,mixed>}
     */
    protected function privateDiscordMessage(
        object $notifiable,
        string $eventType,
        array $subject,
        string $deepLinkPath,
        array $summary = [],
    ): array {
        if (! $notifiable instanceof Nation) {
            throw new \LogicException('Private Discord notifications require a nation notifiable.');
        }

        $notificationId = isset($this->id) && is_string($this->id) && $this->id !== ''
            ? $this->id
            : (string) Str::uuid();

        return [
            'action' => 'PRIVATE_NOTIFICATION',
            'dedupe_key' => 'private-notification:'.$notificationId,
            'payload' => app(PrivateNotificationService::class)->payloadForNation(
                $notifiable,
                $eventType,
                $notificationId,
                $subject,
                $deepLinkPath,
                $summary,
            )->toArray(),
        ];
    }
}
