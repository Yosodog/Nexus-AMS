<?php

namespace App\Notifications;

use App\DataTransferObjects\Discord\WarAlertCounterReference;
use App\Enums\DiscordQueueLane;
use App\Models\Nation;
use App\Notifications\Channels\DiscordQueueChannel;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WarDeclaredDiscordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $warId,
        private readonly Nation $attacker,
        private readonly Nation $defender,
        private readonly ?WarAlertCounterReference $counter,
        private readonly string $channelId,
        private readonly ?CarbonInterface $availableAt = null
    ) {}

    /**
     * @return array<int, class-string<DiscordQueueChannel>>
     */
    public function via(object $notifiable): array
    {
        return [DiscordQueueChannel::class];
    }

    /**
     * @return array{
     *     action: string,
     *     lane: DiscordQueueLane,
     *     channel_id: string,
     *     available_at?: CarbonInterface,
     *     payload: array<string, mixed>
     * }
     */
    public function toDiscordBot(object $notifiable): array
    {
        return [
            'action' => 'WAR_ALERT',
            'lane' => DiscordQueueLane::Alerts,
            'channel_id' => $this->channelId,
            'available_at' => $this->availableAt,
            'payload' => [
                'channel_id' => $this->channelId,
                'war_id' => $this->warId,
                'war_url' => $this->warUrl(),
                'counter' => $this->counter?->toArray() ?? [
                    'id' => null,
                    'url' => null,
                ],
                'attacker' => $this->formatNation($this->attacker),
                'defender' => $this->formatNation($this->defender),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatNation(Nation $nation): array
    {
        $military = $nation->military;
        $alliance = $nation->alliance;

        return [
            'id' => $nation->id,
            'leader_name' => $nation->leader_name,
            'nation_name' => $nation->nation_name,
            'alliance' => $alliance ? [
                'id' => $alliance->id,
                'name' => $alliance->name,
            ] : null,
            'score' => $nation->score,
            'cities' => $nation->num_cities,
            'military' => $military ? [
                'soldiers' => $military->soldiers,
                'tanks' => $military->tanks,
                'aircraft' => $military->aircraft,
                'ships' => $military->ships,
                'missiles' => $military->missiles,
                'nukes' => $military->nukes,
                'spies' => $military->spies,
            ] : null,
            'links' => [
                'nation' => $this->nationUrl($nation->id),
                'alliance' => $alliance ? $this->allianceUrl($alliance->id) : null,
            ],
        ];
    }

    protected function warUrl(): string
    {
        return sprintf('https://politicsandwar.com/nation/war/timeline/war=%d', $this->warId);
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
