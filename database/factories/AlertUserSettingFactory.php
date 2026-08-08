<?php

namespace Database\Factories;

use App\Models\AlertUserSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertUserSetting>
 */
class AlertUserSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => 'UTC',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
            'default_digest_time' => '09:00:00',
            'default_digest_weekday' => 1,
            'discord_enabled' => false,
        ];
    }
}
