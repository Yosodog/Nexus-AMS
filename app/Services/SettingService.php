<?php

namespace App\Services;

use App\Models\Settings;

class SettingService
{

    /**
     * @return int
     */
    public static function getLastScannedBankRecordId(): int
    {
        $id = self::getValue("last_bank_record_id");

        if (is_null(
            $id
        )
        ) { // If the value does not exist, then we need to create it and just return 0
            self::setValue("last_bank_record_id", 0);

            return 0;
        }

        return $id;
    }

    /**
     * @param  string  $key
     *
     * @return mixed
     */
    public static function getValue(string $key): mixed
    {
        return Settings::where("key", $key)->value("value");
    }

    /**
     * Use this to set values for settings. It can also create new setting
     * values if necessary.
     *
     * @param  string  $key
     * @param  mixed  $value
     *
     * @return void
     */
    public static function setValue(string $key, mixed $value): void
    {
        Settings::updateOrCreate(
            ["key" => $key],
            ["value" => $value]
        );
    }

    /**
     * @param  int  $id
     *
     * @return void
     */
    public static function setLastScannedBankRecordId(int $id): void
    {
        self::setValue("last_bank_record_id", $id);
    }

}
