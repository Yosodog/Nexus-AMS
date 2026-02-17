<?php

namespace App\Console\Commands;

use App\Enums\DiscordQueueStatus;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Notifications\BeigeEarlyExitDiscordNotification;
use App\Notifications\Channels\DiscordQueueChannel;
use App\Services\BeigeAlertService;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class DispatchTestBeigeAlert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'beige-alert:test
                            {--mode=turn : "turn" for upcoming beige exits, "early" for forced early-exit payload}
                            {--window=pre_turn : Window for turn mode: pre_turn or post_turn}
                            {--channel= : Override Discord channel ID}
                            {--nationId= : Nation ID for early mode}
                            {--previousBeigeTurns=3 : Previous beige turns value in early payload}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue a test beige alert command for Discord bot validation.';

    /**
     * Execute the console command.
     */
    public function handle(BeigeAlertService $beigeAlertService): int
    {
        $channel = trim((string) ($this->option('channel') ?: SettingService::getBeigeAlertsDiscordChannelId()));

        if ($channel === '') {
            $this->error('No Discord channel configured. Pass --channel or configure Beige Alerts in admin.');

            return self::FAILURE;
        }

        SettingService::setBeigeAlertsEnabled(true);
        SettingService::setBeigeAlertsDiscordChannelId($channel);

        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['turn', 'early'], true)) {
            $this->error('Invalid --mode. Use "turn" or "early".');

            return self::FAILURE;
        }

        $pendingBefore = $this->pendingBeigeAlertCount();

        if ($mode === 'turn') {
            $window = (string) $this->option('window');

            if (! in_array($window, ['pre_turn', 'post_turn'], true)) {
                $this->error('Invalid --window. Use "pre_turn" or "post_turn".');

                return self::FAILURE;
            }

            $beigeAlertService->dispatchTurnWindowAlert($window);
        } else {
            $nationId = $this->option('nationId');
            $nation = Nation::query()
                ->when($nationId, fn ($query) => $query->whereKey((int) $nationId))
                ->when(! $nationId, fn ($query) => $query->inRandomOrder())
                ->with(['alliance', 'military'])
                ->first();

            if (! $nation) {
                $this->error('No nation found. Pass --nationId to target a specific nation.');

                return self::FAILURE;
            }

            Notification::route(DiscordQueueChannel::class, 'discord-bot')
                ->notify(new BeigeEarlyExitDiscordNotification(
                    channelId: $channel,
                    nation: $nation,
                    previousBeigeTurns: max(2, (int) $this->option('previousBeigeTurns')),
                    detectedAt: Carbon::now()->toImmutable()
                ));
        }

        $pendingAfter = $this->pendingBeigeAlertCount();
        $queuedDelta = $pendingAfter - $pendingBefore;

        if ($queuedDelta <= 0) {
            $this->warn('No test beige alert was queued. For turn mode, ensure tracked alliances have nations with beige_turns = 1.');

            return self::FAILURE;
        }

        $latest = DiscordQueue::query()
            ->where('action', 'BEIGE_ALERT')
            ->latest()
            ->first();

        $this->info("Queued {$queuedDelta} BEIGE_ALERT command(s). Latest queue ID: {$latest?->id}");

        return self::SUCCESS;
    }

    protected function pendingBeigeAlertCount(): int
    {
        return DiscordQueue::query()
            ->where('action', 'BEIGE_ALERT')
            ->where('status', DiscordQueueStatus::Pending)
            ->count();
    }
}
