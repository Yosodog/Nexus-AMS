<?php

namespace App\Services;

use App\Models\Setting;

class SettingService
{

    /**
     * @return int
     */
    public static function getLastScannedBankRecordId(): int
    {
        $id = self::getValue("last_bank_record_id");

        if (is_null($id)) { // If the value does not exist, then we need to create it and just return 0
            self::setValue("last_bank_record_id", 0);

            return 0;
        }

        return $id;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public static function getValue(string $key): mixed
    {
        return Setting::where("key", $key)->value("value");
    }

    /**
     * Use this to set values for settings. It can also create new setting
     * values if necessary.
     *
     * @param string $key
     * @param mixed $value
     *
     * @return void
     */
    public static function setValue(string $key, mixed $value): void
    {
        Setting::updateOrCreate(
            ["key" => $key],
            ["value" => $value]
        );
    }

    /**
     * @param int $id
     *
     * @return void
     */
    public static function setLastScannedBankRecordId(int $id): void
    {
        self::setValue("last_bank_record_id", $id);
    }

    /**
     * @return bool
     */
    public static function isWarAidEnabled(): bool
    {
        $value = self::getValue("war_aid_enabled");

        if (is_null($value)) {
            self::setValue("war_aid_enabled", 0); // Default to disabled
            return true;
        }

        return (bool)$value;
    }

    /**
     * @param bool $enabled
     * @return void
     */
    public static function setWarAidEnabled(bool $enabled): void
    {
        self::setValue("war_aid_enabled", $enabled ? 1 : 0);
    }

    /**
     * @return int
     */
    public static function getTopRaidable(): int
    {
        $value = self::getValue("raid_top_alliance_cap");

        if (is_null($value)) {
            self::setTopRaidable(40); // Default to 40
            return true;
        }

        return (int)$value;
    }

    /**
     * @param int $topN
     * @return void
     */
    public static function setTopRaidable(int $topN): void
    {
        self::setValue("raid_top_alliance_cap", $topN);
    }

    /**
     * @return int
     */
    public static function getDirectDepositId(): int
    {
        $value = self::getValue("dd_tax_id");

        if (is_null($value)) {
            self::setDirectDepositId(0); // Default to 0

            return 0;
        }

        return (int)$value;
    }

    /**
     * @param int $DDTaxID
     * @return void
     */
    public static function setDirectDepositId(int $DDTaxID): void
    {
        self::setValue("dd_tax_id", $DDTaxID);
    }

    /**
     * @return int
     */
    public static function getDirectDepositFallbackId(): int
    {
        $value = self::getValue("dd_fallback_tax_id");

        if (is_null($value)) {
            self::setDirectDepositFallbackId(0); // Default to 0

            return 0;
        }

        return (int)$value;
    }

    /**
     * @param int $DDTaxID
     * @return void
     */
    public static function setDirectDepositFallbackId(int $DDTaxID): void
    {
        self::setValue("dd_fallback_tax_id", $DDTaxID);
    }

}
