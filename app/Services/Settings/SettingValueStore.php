<?php

namespace App\Services\Settings;

use App\Models\Setting;

class SettingValueStore
{
    public function get(string $key): mixed
    {
        return Setting::query()->where('key', $key)->value('value');
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public function getString(string $key, string $default): string
    {
        $value = $this->get($key);

        if (is_null($value) || $value === '') {
            $this->set($key, $default);

            return $default;
        }

        return (string) $value;
    }

    public function getStringWithoutPersisting(string $key, string $default): string
    {
        $value = $this->get($key);

        return is_null($value) || $value === '' ? $default : (string) $value;
    }
}
