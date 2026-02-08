<?php

namespace App\Services\Inactivity\Actions;

use App\Models\InactivityEvent;
use App\Models\Nation;
use App\Notifications\InactivityDiscordNotification;
use App\Services\Inactivity\InactivityActionContext;
use App\Services\Inactivity\InactivityActionHandler;
use App\Services\Inactivity\InactivityActionResult;
use App\Services\Inactivity\InactivityMessageBuilder;
use App\Services\SettingService;

class SendDiscordNotificationAction implements InactivityActionHandler
{
    public function __construct(private readonly InactivityMessageBuilder $messageBuilder) {}

    public function handle(Nation $nation, InactivityEvent $event, InactivityActionContext $context): InactivityActionResult
    {
        $channelId = SettingService::getInactivityDiscordChannelId();

        if ($channelId === '') {
            return new InactivityActionResult;
        }

        $accountsUrl = $context->accountsUrl ?? route('accounts');
        $message = $this->messageBuilder->buildDiscordMessage(
            $nation->leader_name,
            $nation->nation_name,
            $nation->id,
            $context->lastActiveAt,
            $context->thresholdHours,
            $accountsUrl
        );

        $discordUserId = $nation->user?->activeDiscordAccount()?->discord_id;
        $mention = $discordUserId ? "<@{$discordUserId}> " : '';

        $nation->notify(new InactivityDiscordNotification(
            $channelId,
            $mention.$message,
            $discordUserId,
            [
                'nation_id' => $nation->id,
                'leader_name' => $nation->leader_name,
                'nation_name' => $nation->nation_name,
                'accounts_url' => $accountsUrl,
                'last_active_at' => $context->lastActiveAt->toIso8601String(),
            ]
        ));

        return new InactivityActionResult(notificationSent: true);
    }
}
