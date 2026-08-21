<?php

namespace App\Services\Settings;

use Illuminate\Support\Carbon;

class DataSyncSettings
{
    public const DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD = 0;

    public const DEFAULT_RAID_MINIMUM_INACTIVE_TURNS = 0;

    public const MAX_RAID_ACTIVITY_CITY_THRESHOLD = 1000;

    public const MAX_RAID_MINIMUM_INACTIVE_TURNS = 4380;

    public function __construct(private readonly SettingValueStore $settings) {}

    public function getLastScannedBankRecordId(): int
    {
        $id = $this->settings->get('last_bank_record_id');

        if (is_null($id)) {
            $this->settings->set('last_bank_record_id', 0);

            return 0;
        }

        return $id;
    }

    public function setLastScannedBankRecordId(int $id): void
    {
        $this->settings->set('last_bank_record_id', $id);
    }

    public function getCityAverage(): ?float
    {
        $value = $this->settings->get('pw_city_average');

        return $value === null ? null : (float) $value;
    }

    public function setCityAverage(float $average): void
    {
        $this->settings->set('pw_city_average', $average);
    }

    public function getCityAverageUpdatedAt(): ?Carbon
    {
        $value = $this->settings->get('pw_city_average_updated_at');

        return $value ? Carbon::parse($value) : null;
    }

    public function setCityAverageUpdatedAt(Carbon $timestamp): void
    {
        $this->settings->set('pw_city_average_updated_at', $timestamp->toIso8601String());
    }

    public function getTopRaidable(): int
    {
        $value = $this->settings->get('raid_top_alliance_cap');

        if (is_null($value)) {
            $this->setTopRaidable(40);

            return 40;
        }

        return (int) $value;
    }

    public function setTopRaidable(int $topN): void
    {
        $this->settings->set('raid_top_alliance_cap', $topN);
    }

    public function getRaidActivityCityThreshold(): int
    {
        $value = $this->settings->get('raid_activity_city_threshold');

        if (is_null($value)) {
            $this->setRaidActivityCityThreshold(self::DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD);

            return self::DEFAULT_RAID_ACTIVITY_CITY_THRESHOLD;
        }

        return (int) $value;
    }

    public function setRaidActivityCityThreshold(int $cityThreshold): void
    {
        $this->settings->set(
            'raid_activity_city_threshold',
            max(0, min(self::MAX_RAID_ACTIVITY_CITY_THRESHOLD, $cityThreshold)),
        );
    }

    public function getRaidMinimumInactiveTurns(): int
    {
        $value = $this->settings->get('raid_minimum_inactive_turns');

        if (is_null($value)) {
            $this->setRaidMinimumInactiveTurns(self::DEFAULT_RAID_MINIMUM_INACTIVE_TURNS);

            return self::DEFAULT_RAID_MINIMUM_INACTIVE_TURNS;
        }

        return (int) $value;
    }

    public function setRaidMinimumInactiveTurns(int $inactiveTurns): void
    {
        $this->settings->set(
            'raid_minimum_inactive_turns',
            max(0, min(self::MAX_RAID_MINIMUM_INACTIVE_TURNS, $inactiveTurns)),
        );
    }

    public function getLastNationSyncBatchId(): string
    {
        return $this->getLastManualNationSyncBatchId();
    }

    public function setLastNationSyncBatchId(string $batchId): void
    {
        $this->setLastManualNationSyncBatchId($batchId);
    }

    public function getLastManualNationSyncBatchId(): string
    {
        $value = $this->settings->get('last_nation_sync_batch_id');

        if (is_null($value)) {
            $this->setLastManualNationSyncBatchId('');

            return '';
        }

        return $value;
    }

    public function setLastManualNationSyncBatchId(string $batchId): void
    {
        $this->settings->set('last_nation_sync_batch_id', $batchId);
    }

    public function getLastRollingNationSyncBatchId(): string
    {
        $value = $this->settings->get('last_rolling_nation_sync_batch_id');

        if (is_null($value)) {
            $this->setLastRollingNationSyncBatchId('');

            return '';
        }

        return $value;
    }

    public function setLastRollingNationSyncBatchId(string $batchId): void
    {
        $this->settings->set('last_rolling_nation_sync_batch_id', $batchId);
    }

    public function getLastAllianceSyncBatchId(): string
    {
        $value = $this->settings->get('last_alliance_sync_batch_id');

        if (is_null($value)) {
            $this->setLastAllianceSyncBatchId('');

            return '';
        }

        return $value;
    }

    public function setLastAllianceSyncBatchId(string $batchId): void
    {
        $this->settings->set('last_alliance_sync_batch_id', $batchId);
    }

    public function getLastWarSyncBatchId(): string
    {
        $value = $this->settings->get('last_war_sync_batch_id');

        if (is_null($value)) {
            $this->setLastWarSyncBatchId('');

            return '';
        }

        return $value;
    }

    public function setLastWarSyncBatchId(string $batchId): void
    {
        $this->settings->set('last_war_sync_batch_id', $batchId);
    }
}
