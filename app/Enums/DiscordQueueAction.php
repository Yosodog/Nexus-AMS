<?php

namespace App\Enums;

enum DiscordQueueAction: string
{
    case AlertDeliveryV1 = 'ALERT_DELIVERY_V1';
    case ApplicationDiscordReconcile = 'APPLICATION_DISCORD_RECONCILE';
    case WarAlert = 'WAR_ALERT';
    case AllianceDeparture = 'ALLIANCE_DEPARTURE';
    case InactivityAlert = 'INACTIVITY_ALERT';
    case MemberProfileSync = 'MEMBER_PROFILE_SYNC';
    case AllianceRoleRemoval = 'ALLIANCE_ROLE_REMOVAL';
    case BeigeAlert = 'BEIGE_ALERT';
    case CityTierSync = 'CITY_TIER_SYNC';
    case WarRoomCreate = 'WAR_ROOM_CREATE';
    case WarRoomArchive = 'WAR_ROOM_ARCHIVE';
    case PrivateNotification = 'PRIVATE_NOTIFICATION';

    /** @return list<DiscordQueueLane> */
    public function allowedLanes(): array
    {
        return match ($this) {
            self::AlertDeliveryV1 => [DiscordQueueLane::Alerts, DiscordQueueLane::Digests],
            self::WarAlert,
            self::AllianceDeparture,
            self::InactivityAlert,
            self::BeigeAlert,
            self::PrivateNotification => [DiscordQueueLane::Alerts],
            self::ApplicationDiscordReconcile,
            self::MemberProfileSync,
            self::AllianceRoleRemoval,
            self::CityTierSync,
            self::WarRoomCreate,
            self::WarRoomArchive => [DiscordQueueLane::SideEffects],
        };
    }

    public function supportsLane(DiscordQueueLane $lane): bool
    {
        return in_array($lane, $this->allowedLanes(), true);
    }
}
