<?php

namespace App\Http\Resources\Discord;

use App\Models\AlertUserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertUserSetting */
class AlertUserSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quietStart = $this->time($this->quiet_hours_start);
        $quietEnd = $this->time($this->quiet_hours_end);

        return [
            'timezone' => $this->timezone,
            'quiet_hours' => [
                'enabled' => $quietStart !== null && $quietEnd !== null,
                'start' => $quietStart,
                'end' => $quietEnd,
            ],
            'default_digest' => [
                'time' => $this->time($this->default_digest_time),
                'weekday' => $this->default_digest_weekday,
            ],
            'discord_enabled' => $this->discord_enabled,
            'uses_legacy_defaults' => ! $this->exists,
        ];
    }

    private function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 5);
    }
}
