<?php

namespace Database\Factories;

use App\Enums\AlertDestinationKind;
use App\Models\AlertDailyMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertDailyMetric>
 */
class AlertDailyMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metric_date' => now()->toDateString(),
            'scope_key' => 'global',
            'event_key' => 'nation.alliance.changed',
            'destination_kind' => AlertDestinationKind::Web->value,
            'outcome' => 'delivered',
            'total' => 1,
        ];
    }
}
