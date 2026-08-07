<?php

namespace App\Services\Settings;

class DiscordSettings
{
    public const DEFAULT_CITY_TIER_BUCKET_SIZE = 10;

    public function __construct(private readonly SettingValueStore $settings) {}

    public function isVerificationRequired(): bool
    {
        $value = $this->settings->get('require_discord_verification');

        if (is_null($value)) {
            $this->setVerificationRequired(false);

            return false;
        }

        return (bool) $value;
    }

    public function setVerificationRequired(bool $required): void
    {
        $this->settings->set('require_discord_verification', $required ? 1 : 0);
    }

    public function arePrivateNotificationsEnabled(): bool
    {
        return (bool) ($this->settings->get('discord_private_notifications_enabled') ?? false);
    }

    public function setPrivateNotificationsEnabled(bool $enabled): void
    {
        $this->settings->set('discord_private_notifications_enabled', $enabled ? 1 : 0);
    }

    public function getWarAlertChannelId(): string
    {
        return (string) ($this->settings->get('discord_war_alert_channel_id') ?? '');
    }

    public function setWarAlertChannelId(?string $channelId): void
    {
        $this->settings->set('discord_war_alert_channel_id', $channelId ?? '');
    }

    public function getCityTierBucketSize(): int
    {
        $value = $this->settings->get('discord_city_tier_bucket_size');

        return max(1, min(100, (int) ($value ?? self::DEFAULT_CITY_TIER_BUCKET_SIZE)));
    }

    public function setCityTierBucketSize(int $bucketSize): void
    {
        $this->settings->set('discord_city_tier_bucket_size', max(1, min(100, $bucketSize)));
    }

    public function getWarRoomForumId(): string
    {
        return (string) ($this->settings->get('discord_war_room_forum_id') ?? '');
    }

    public function setWarRoomForumId(?string $channelId): void
    {
        $this->settings->set('discord_war_room_forum_id', $channelId ?? '');
    }

    public function getWarRoomDefenseRoleId(): string
    {
        return $this->settings->getString('discord_war_room_defense_role_id', '');
    }

    public function setWarRoomDefenseRoleId(?string $roleId): void
    {
        $this->settings->set('discord_war_room_defense_role_id', $roleId ?? '');
    }

    public function isWarCounterAutoCreationEnabled(): bool
    {
        $value = $this->settings->get('war_counter_auto_creation_enabled');

        if (is_null($value)) {
            $value = $this->settings->get('discord_war_room_creation_enabled');
        }

        if (is_null($value)) {
            $this->setWarCounterAutoCreationEnabled(true);

            return true;
        }

        return (bool) $value;
    }

    public function setWarCounterAutoCreationEnabled(bool $enabled): void
    {
        $this->settings->set('war_counter_auto_creation_enabled', $enabled ? 1 : 0);
    }

    public function getAllianceDepartureChannelId(): string
    {
        $channelId = $this->settings->get('discord_alliance_departure_channel_id');

        if (is_string($channelId) && $channelId !== '') {
            return $channelId;
        }

        return $this->getWarAlertChannelId();
    }

    public function setAllianceDepartureChannelId(?string $channelId): void
    {
        $this->settings->set('discord_alliance_departure_channel_id', $channelId ?? '');
    }

    public function isWarAlertEnabled(): bool
    {
        return (bool) ($this->settings->get('discord_war_alert_enabled') ?? false);
    }

    public function setWarAlertEnabled(bool $enabled): void
    {
        $this->settings->set('discord_war_alert_enabled', $enabled ? 1 : 0);
    }

    public function isAllianceDepartureEnabled(): bool
    {
        $value = $this->settings->get('discord_alliance_departure_enabled');

        if (is_null($value)) {
            return $this->isWarAlertEnabled();
        }

        return (bool) $value;
    }

    public function setAllianceDepartureEnabled(bool $enabled): void
    {
        $this->settings->set('discord_alliance_departure_enabled', $enabled ? 1 : 0);
    }

    public function isBeigeAlertsEnabled(): bool
    {
        $value = $this->settings->get('beige_alerts_enabled');

        if (is_null($value)) {
            $this->setBeigeAlertsEnabled(false);

            return false;
        }

        return (bool) $value;
    }

    public function setBeigeAlertsEnabled(bool $enabled): void
    {
        $this->settings->set('beige_alerts_enabled', $enabled ? 1 : 0);
    }

    public function getBeigeAlertsChannelId(): string
    {
        return (string) ($this->settings->get('beige_alerts_discord_channel_id') ?? '');
    }

    public function setBeigeAlertsChannelId(?string $channelId): void
    {
        $this->settings->set('beige_alerts_discord_channel_id', $channelId ?? '');
    }
}
