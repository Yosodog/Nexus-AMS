<?php

namespace Database\Factories;

use App\Models\AlertSubscription;
use App\Models\AlertSubscriptionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertSubscriptionEvent>
 */
class AlertSubscriptionEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_subscription_id' => AlertSubscription::factory(),
            'event_key' => 'nation.alliance.changed',
        ];
    }
}
