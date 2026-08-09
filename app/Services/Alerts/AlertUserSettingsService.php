<?php

namespace App\Services\Alerts;

use App\Models\AlertUserSetting;
use App\Models\DiscordNotificationPreference;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AlertUserSettingsService
{
    public function current(User $user): AlertUserSetting
    {
        $settings = AlertUserSetting::query()->whereBelongsTo($user)->first();
        if ($settings !== null) {
            return $settings;
        }

        return new AlertUserSetting([
            'user_id' => $user->id,
            'timezone' => 'UTC',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'default_digest_time' => '09:00:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => $this->legacyDiscordEnabled($user),
        ]);
    }

    public function isDiscordEnabled(User $user): bool
    {
        $stored = AlertUserSetting::query()->whereBelongsTo($user)->value('discord_enabled');

        return $stored === null ? $this->legacyDiscordEnabled($user) : (bool) $stored;
    }

    /** @param array<string, mixed> $input */
    public function update(User $user, array $input): AlertUserSetting
    {
        $validator = Validator::make($input, [
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'quiet_hours_start' => ['nullable', 'required_with:quiet_hours_end', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'required_with:quiet_hours_start', 'date_format:H:i'],
            'default_digest_time' => ['required', 'date_format:H:i'],
            'default_digest_weekday' => ['required', 'integer', 'between:1,7'],
            'discord_enabled' => ['required', 'boolean'],
        ]);
        $validator->after(function ($validator) use ($input): void {
            $quietStart = $input['quiet_hours_start'] ?? null;
            $quietEnd = $input['quiet_hours_end'] ?? null;
            if (is_string($quietStart) && $quietStart !== '' && $quietStart === $quietEnd) {
                $validator->errors()->add(
                    'quiet_hours_end',
                    'Quiet hours must have different start and end times.',
                );
            }
        });
        $validated = $validator->validate();

        return DB::transaction(function () use ($user, $validated): AlertUserSetting {
            $settings = AlertUserSetting::query()->firstOrCreate(['user_id' => $user->id]);
            $settings->fill([
                'timezone' => $validated['timezone'],
                'quiet_hours_start' => $this->databaseTime($validated['quiet_hours_start'] ?? null),
                'quiet_hours_end' => $this->databaseTime($validated['quiet_hours_end'] ?? null),
                'default_digest_time' => $this->databaseTime($validated['default_digest_time']),
                'default_digest_weekday' => (int) $validated['default_digest_weekday'],
                'discord_enabled' => in_array($validated['discord_enabled'], [true, 1, '1'], true),
            ])->save();

            return $settings->refresh();
        }, attempts: 3);
    }

    private function databaseTime(?string $time): ?string
    {
        return $time === null || $time === '' ? null : $time.':00';
    }

    private function legacyDiscordEnabled(User $user): bool
    {
        if (! SettingService::areDiscordPrivateNotificationsEnabled()) {
            return false;
        }

        return (bool) DiscordNotificationPreference::query()
            ->whereBelongsTo($user)
            ->where('category', 'watchlists')
            ->value('enabled');
    }
}
