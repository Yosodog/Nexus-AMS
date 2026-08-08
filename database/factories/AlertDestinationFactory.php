<?php

namespace Database\Factories;

use App\Enums\AlertDestinationHealth;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDestination;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertDestination>
 */
class AlertDestinationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'kind' => AlertDestinationKind::DiscordChannel,
            'guild_id' => '1'.fake()->unique()->numerify('#################'),
            'channel_id' => '2'.fake()->unique()->numerify('#################'),
            'mention_role_ids' => [],
            'health_status' => AlertDestinationHealth::Unverified,
            'fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
