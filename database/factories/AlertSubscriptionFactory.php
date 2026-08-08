<?php

namespace Database\Factories;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertSubscriptionStatus;
use App\Enums\AlertSubscriptionType;
use App\Models\AlertSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertSubscription>
 */
class AlertSubscriptionFactory extends Factory
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
            'type' => AlertSubscriptionType::Nation,
            'name' => fake()->words(3, true),
            'config' => fn (array $attributes): array => [
                'target_id' => $attributes['target_id'],
                'events' => ['city_count_changed'],
            ],
            'target_type' => 'nation',
            'target_id' => fake()->numberBetween(1, 999_999),
            'filter_config' => [],
            'is_active' => true,
            'status' => AlertSubscriptionStatus::Active,
            'cooldown_minutes' => 60,
            'delivery_mode' => AlertDeliveryMode::Immediate,
            'discord_enabled' => false,
            'rearm_percent' => 1,
            'active_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
