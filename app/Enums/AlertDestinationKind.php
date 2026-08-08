<?php

namespace App\Enums;

enum AlertDestinationKind: string
{
    case Web = 'web';
    case DiscordDm = 'discord_dm';
    case DiscordChannel = 'discord_channel';
    case DiscordForum = 'discord_forum';

    public function isDiscord(): bool
    {
        return $this !== self::Web;
    }
}
