<?php

namespace App\Services\Alerts;

use App\Enums\AlertDeliveryMode;
use App\Models\AlertRoute;
use App\Models\AlertSubscription;
use App\Models\AlertUserSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AlertDeliveryPolicy
{
    public function scheduledAtForSubscription(AlertSubscription $subscription, User $user): ?CarbonInterface
    {
        $settings = AlertUserSetting::query()->whereBelongsTo($user)->first();
        $timezone = $subscription->timezone ?: $settings?->timezone ?: 'UTC';
        $mode = $subscription->delivery_mode ?? AlertDeliveryMode::Immediate;

        return $this->scheduledAt(
            mode: $mode,
            timezone: $timezone,
            quietStart: $settings?->quiet_hours_start,
            quietEnd: $settings?->quiet_hours_end,
            digestTime: $settings?->default_digest_time ?: '09:00:00',
            digestWeekday: (int) ($settings?->default_digest_weekday ?? 1),
        );
    }

    public function scheduledAtForRoute(AlertRoute $route, bool $mayBypassQuietHours): ?CarbonInterface
    {
        $policy = $route->delivery_policy;
        $mode = AlertDeliveryMode::tryFrom((string) ($policy['mode'] ?? 'immediate'))
            ?? AlertDeliveryMode::Immediate;

        return $this->scheduledAt(
            mode: $mode,
            timezone: (string) ($policy['timezone'] ?? 'UTC'),
            quietStart: $mayBypassQuietHours ? null : ($policy['quiet_hours_start'] ?? null),
            quietEnd: $mayBypassQuietHours ? null : ($policy['quiet_hours_end'] ?? null),
            digestTime: (string) ($policy['digest_time'] ?? '09:00:00'),
            digestWeekday: (int) ($policy['digest_weekday'] ?? 1),
        );
    }

    private function scheduledAt(
        AlertDeliveryMode $mode,
        string $timezone,
        ?string $quietStart,
        ?string $quietEnd,
        string $digestTime,
        int $digestWeekday,
    ): ?CarbonInterface {
        $now = CarbonImmutable::now($timezone);

        if ($mode === AlertDeliveryMode::Daily) {
            $scheduled = $now->setTimeFromTimeString($digestTime);

            return ($scheduled->isFuture() ? $scheduled : $scheduled->addDay())->utc();
        }

        if ($mode === AlertDeliveryMode::Weekly) {
            $weekday = max(1, min(7, $digestWeekday));
            $scheduled = $now->nextOrSame($weekday)->setTimeFromTimeString($digestTime);

            return ($scheduled->isFuture() ? $scheduled : $scheduled->addWeek())->utc();
        }

        if ($quietStart === null || $quietEnd === null) {
            return null;
        }

        $start = $now->setTimeFromTimeString($quietStart);
        $end = $now->setTimeFromTimeString($quietEnd);
        if ($start->equalTo($end)) {
            return null;
        }

        if ($start->lessThan($end)) {
            return $now->betweenIncluded($start, $end) && ! $now->equalTo($end) ? $end->utc() : null;
        }

        if ($now->greaterThanOrEqualTo($start)) {
            return $end->addDay()->utc();
        }

        return $now->lessThan($end) ? $end->utc() : null;
    }
}
