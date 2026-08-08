<?php

namespace Database\Factories;

use App\Enums\AlertDeliveryMode;
use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationKind;
use App\Models\AlertDelivery;
use App\Models\AlertOccurrence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertDelivery>
 */
class AlertDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_occurrence_id' => AlertOccurrence::factory(),
            'destination_kind' => AlertDestinationKind::Web,
            'delivery_mode' => AlertDeliveryMode::Immediate,
            'status' => AlertDeliveryStatus::Delivered,
            'match_key' => hash('sha256', (string) Str::uuid()),
            'delivered_at' => now(),
        ];
    }
}
