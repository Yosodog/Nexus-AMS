<?php

namespace App\Enums;

enum MemberInactivityAutomation: string
{
    case AutoEnrollDirectDeposit = 'auto_enroll_direct_deposit';
    case SendInGameMessage = 'send_in_game_message';
    case SendDiscordNotification = 'send_discord_notification';
    case DisableAccount = 'disable_account';

    public function label(): string
    {
        return match ($this) {
            self::AutoEnrollDirectDeposit => 'Automatic direct deposit enrollment',
            self::SendInGameMessage => 'In-game inactivity messages',
            self::SendDiscordNotification => 'Discord inactivity alerts',
            self::DisableAccount => 'Automatic account disabling',
        };
    }

    public function inactivityAction(): ?InactivityAction
    {
        return match ($this) {
            self::AutoEnrollDirectDeposit => InactivityAction::AutoEnrollDirectDeposit,
            self::SendInGameMessage => InactivityAction::SendInGameMessage,
            self::SendDiscordNotification => InactivityAction::SendDiscordNotification,
            self::DisableAccount => null,
        };
    }

    public static function fromInactivityAction(InactivityAction $action): self
    {
        return match ($action) {
            InactivityAction::AutoEnrollDirectDeposit => self::AutoEnrollDirectDeposit,
            InactivityAction::SendInGameMessage => self::SendInGameMessage,
            InactivityAction::SendDiscordNotification => self::SendDiscordNotification,
        };
    }
}
