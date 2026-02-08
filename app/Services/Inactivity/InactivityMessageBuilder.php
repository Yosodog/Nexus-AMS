<?php

namespace App\Services\Inactivity;

use Carbon\CarbonInterface;

class InactivityMessageBuilder
{
    public function buildSubject(): string
    {
        return 'Inactivity Notice';
    }

    public function buildAutoEnrollMessage(
        string $leaderName,
        CarbonInterface $lastActiveAt,
        int $thresholdHours,
        string $accountsUrl
    ): string {
        return <<<MESSAGE
Hello {$leaderName},

Our system detected that you have been inactive for more than {$thresholdHours} hours (last active: {$lastActiveAt->toDayDateTimeString()}).

Because of this, we have automatically enrolled you in Direct Deposit so your deposits continue without interruption.

If you want to opt out, you can do so here: {$accountsUrl}

Please log into Politics & War to resume activity.
MESSAGE;
    }

    public function buildInactiveNoticeMessage(
        string $leaderName,
        CarbonInterface $lastActiveAt,
        int $thresholdHours
    ): string {
        return <<<MESSAGE
Hello {$leaderName},

Our system detected that you have been inactive for more than {$thresholdHours} hours (last active: {$lastActiveAt->toDayDateTimeString()}).

Please log into Politics & War to resume activity.
MESSAGE;
    }

    public function buildDiscordMessage(
        string $leaderName,
        string $nationName,
        int $nationId,
        CarbonInterface $lastActiveAt,
        int $thresholdHours,
        string $accountsUrl
    ): string {
        return "Inactivity alert for {$leaderName} ({$nationName}, #{$nationId}). ".
            "Last active: {$lastActiveAt->toDayDateTimeString()} (threshold: {$thresholdHours}h). ".
            "Accounts: {$accountsUrl}";
    }
}
