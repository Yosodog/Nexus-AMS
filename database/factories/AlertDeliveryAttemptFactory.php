<?php

namespace Database\Factories;

use App\Enums\AlertAttemptStatus;
use App\Models\AlertDeliveryAttempt;
use App\Models\AlertDeliveryBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertDeliveryAttempt>
 */
class AlertDeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_delivery_batch_id' => AlertDeliveryBatch::factory(),
            'attempt_number' => 1,
            'adapter' => 'discord',
            'status' => AlertAttemptStatus::Started,
            'started_at' => now(),
            'retryable' => false,
        ];
    }
}
