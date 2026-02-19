<?php

namespace App\Notifications;

use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BeigeEarlyExitDiscordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $channelId,
        private readonly Nation $nation,
        private readonly int $previousBeigeTurns,
        private readonly CarbonImmutable $detectedAt
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
            'action' => 'BEIGE_ALERT',
            'channel_id' => $this->channelId,
            'payload' => [
                'channel_id' => $this->channelId,
                'event_type' => 'early_exit',
                'detected_at' => $this->detectedAt->toIso8601String(),
                'previous_beige_turns' => $this->previousBeigeTurns,
                'nation' => $this->formatNation($this->nation),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatNation(Nation $nation): array
    {
        $alliance = $nation->alliance;
        $military = $nation->military;

        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'alliance' => $alliance ? [
                'id' => $alliance->id,
                'name' => $alliance->name,
            ] : null,
            'cities' => $nation->num_cities,
            'score' => $nation->score,
            'beige_turns' => (int) $nation->beige_turns,
            'military' => $military ? [
                'soldiers' => (int) $military->soldiers,
                'tanks' => (int) $military->tanks,
                'aircraft' => (int) $military->aircraft,
                'ships' => (int) $military->ships,
                'missiles' => (int) $military->missiles,
                'nukes' => (int) $military->nukes,
                'spies' => (int) $military->spies,
            ] : null,
            'links' => [
                'nation' => $this->nationUrl((int) $nation->id),
                'alliance' => $alliance ? $this->allianceUrl((int) $alliance->id) : null,
            ],
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
