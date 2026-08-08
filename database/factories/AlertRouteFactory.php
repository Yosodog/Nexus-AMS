<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Models\AlertDestination;
use App\Models\AlertRoute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertRoute>
 */
class AlertRouteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_destination_id' => AlertDestination::factory(),
            'name' => fake()->words(3, true),
            'event_key' => 'milcom.incident.detected',
            'minimum_severity' => AlertSeverity::Normal,
            'filter_config' => [],
            'delivery_policy' => ['mode' => 'immediate', 'timezone' => 'UTC'],
            'is_active' => true,
            'active_fingerprint' => hash('sha256', (string) Str::uuid()),
        ];
    }
}
