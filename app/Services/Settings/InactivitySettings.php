<?php

namespace App\Services\Settings;

class InactivitySettings
{
    public function __construct(private readonly SettingValueStore $settings) {}

    public function isEnabled(): bool
    {
        $value = $this->settings->get('inactivity_mode_enabled');

        if (is_null($value)) {
            $this->setEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->set('inactivity_mode_enabled', $enabled ? 1 : 0);
    }

    public function getThresholdHours(): int
    {
        $value = $this->settings->get('inactivity_threshold_hours');

        if (is_null($value)) {
            $this->setThresholdHours(72);

            return 72;
        }

        return max(1, (int) $value);
    }

    public function setThresholdHours(int $hours): void
    {
        $this->settings->set('inactivity_threshold_hours', max(1, $hours));
    }

    /**
     * @return array<int, string>
     */
    public function getActions(): array
    {
        $value = $this->settings->get('inactivity_actions');

        if (is_null($value)) {
            $this->setActions([]);

            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }

        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $actions
     */
    public function setActions(array $actions): void
    {
        $actions = array_values(array_filter($actions, 'is_string'));

        $this->settings->set('inactivity_actions', json_encode($actions));
    }

    public function getCooldownHours(): int
    {
        $value = $this->settings->get('inactivity_notification_cooldown_hours');

        if (is_null($value)) {
            $this->setCooldownHours(24);

            return 24;
        }

        return max(1, (int) $value);
    }

    public function setCooldownHours(int $hours): void
    {
        $this->settings->set('inactivity_notification_cooldown_hours', max(1, $hours));
    }

    public function getDiscordChannelId(): string
    {
        return (string) ($this->settings->get('inactivity_discord_channel_id') ?? '');
    }

    public function setDiscordChannelId(?string $channelId): void
    {
        $this->settings->set('inactivity_discord_channel_id', $channelId ?? '');
    }

    public function areRepeatNotificationsEnabled(): bool
    {
        $value = $this->settings->get('inactivity_repeat_notifications_enabled');

        if (is_null($value)) {
            $this->setRepeatNotificationsEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setRepeatNotificationsEnabled(bool $enabled): void
    {
        $this->settings->set('inactivity_repeat_notifications_enabled', $enabled ? 1 : 0);
    }
}
