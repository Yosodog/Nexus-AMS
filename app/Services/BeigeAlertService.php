<?php

namespace App\Services;

use App\Enums\DiscordQueueStatus;
use App\Models\BeigeAlertAlliance;
use App\Models\DiscordQueue;
use App\Models\Nation;
use App\Notifications\BeigeEarlyExitDiscordNotification;
use App\Notifications\BeigeTurnWindowDiscordNotification;
use App\Notifications\Channels\DiscordQueueChannel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class BeigeAlertService
{
    /**
     * @return EloquentCollection<int, Nation>
     */
    public function getNationsLeavingBeigeNextTurn(): EloquentCollection
    {
        $trackedAllianceIds = BeigeAlertAlliance::query()->pluck('alliance_id');

        if ($trackedAllianceIds->isEmpty()) {
            return new EloquentCollection;
        }

        return Nation::query()
            ->whereIn('alliance_id', $trackedAllianceIds)
            ->where('beige_turns', 1)
            ->with(['alliance', 'military'])
            ->orderByDesc('score')
            ->get();
    }

    public function dispatchTurnWindowAlert(string $window, ?CarbonInterface $dispatchedAt = null): void
    {
        $channelId = SettingService::getBeigeAlertsDiscordChannelId();

        if (! SettingService::isBeigeAlertsEnabled() || $channelId === '') {
            return;
        }

        $nations = $this->getNationsLeavingBeigeNextTurn();

        if ($nations->isEmpty()) {
            return;
        }

        $dispatchedAt = $dispatchedAt ?? now();
        $turnChangeAt = $this->nextTurnChangeAt($dispatchedAt);

        try {
            Notification::route(DiscordQueueChannel::class, 'discord-bot')
                ->notify(new BeigeTurnWindowDiscordNotification(
                    channelId: $channelId,
                    window: $window,
                    turnChangeAt: $turnChangeAt,
                    nations: $nations
                ));
        } catch (Throwable $exception) {
            Log::error('Failed to queue beige turn-window Discord alert', [
                'window' => $window,
                'nation_count' => $nations->count(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function maybeDispatchEarlyExitAlert(
        int $nationId,
        ?int $allianceId,
        int $previousBeigeTurns,
        int $currentBeigeTurns,
        ?CarbonInterface $detectedAt = null
    ): void {
        if (! SettingService::isBeigeAlertsEnabled()) {
            return;
        }

        $channelId = SettingService::getBeigeAlertsDiscordChannelId();

        if ($channelId === '') {
            return;
        }

        if ($currentBeigeTurns !== 0 || $previousBeigeTurns <= 1) {
            return;
        }

        $detectedAt = $detectedAt ?? now();

        if ($this->isWithinTurnChangeBuffer($detectedAt, 5)) {
            return;
        }

        if (! BeigeAlertAlliance::query()->where('alliance_id', $allianceId)->exists()) {
            return;
        }

        if ($this->hasRecentEarlyExitAlert($nationId)) {
            return;
        }

        $nation = Nation::query()
            ->with(['alliance', 'military'])
            ->find($nationId);

        if (! $nation) {
            return;
        }

        try {
            Notification::route(DiscordQueueChannel::class, 'discord-bot')
                ->notify(new BeigeEarlyExitDiscordNotification(
                    channelId: $channelId,
                    nation: $nation,
                    previousBeigeTurns: $previousBeigeTurns,
                    detectedAt: CarbonImmutable::instance($detectedAt)
                ));
        } catch (Throwable $exception) {
            Log::error('Failed to queue beige early-exit Discord alert', [
                'nation_id' => $nationId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function isWithinTurnChangeBuffer(CarbonInterface $time, int $minutes): bool
    {
        $pointInTime = CarbonImmutable::instance($time)->seconds(0);
        $nextTurnChange = $this->nextTurnChangeAt($pointInTime);
        $previousTurnChange = $nextTurnChange->subHours(2);
        $limitSeconds = $minutes * 60;

        return abs($pointInTime->diffInSeconds($nextTurnChange, false)) <= $limitSeconds
            || abs($pointInTime->diffInSeconds($previousTurnChange, false)) <= $limitSeconds;
    }

    public function nextTurnChangeAt(CarbonInterface $time): CarbonImmutable
    {
        $pointInTime = CarbonImmutable::instance($time)->seconds(0);
        $candidate = $pointInTime->startOfHour();

        if ($candidate->hour % 2 !== 0) {
            $candidate = $candidate->addHour();
        }

        if ($candidate->lessThanOrEqualTo($pointInTime)) {
            $candidate = $candidate->addHours(2);
        }

        return $candidate;
    }

    protected function hasRecentEarlyExitAlert(int $nationId): bool
    {
        return DiscordQueue::query()
            ->where('action', 'BEIGE_ALERT')
            ->where('status', DiscordQueueStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereJsonContains('payload->event_type', 'early_exit')
            ->whereJsonContains('payload->nation->id', $nationId)
            ->exists();
    }
}
