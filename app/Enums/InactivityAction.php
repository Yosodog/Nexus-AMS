<?php

namespace App\Enums;

enum InactivityAction: string
{
    case AutoEnrollDirectDeposit = 'AUTO_ENROLL_DIRECT_DEPOSIT';
    case SendInGameMessage = 'SEND_IN_GAME_MESSAGE';
    case SendDiscordNotification = 'SEND_DISCORD_NOTIFICATION';

    public function label(): string
    {
        return match ($this) {
            self::AutoEnrollDirectDeposit => 'Auto-enroll Direct Deposit',
            self::SendInGameMessage => 'Send In-Game Message',
            self::SendDiscordNotification => 'Send Discord Notification',
        };
    }
}
