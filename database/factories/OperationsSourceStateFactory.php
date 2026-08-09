<?php

namespace Database\Factories;

use App\Models\OperationsSourceState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationsSourceState>
 */
class OperationsSourceStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'applications',
            'status' => OperationsSourceState::STATUS_HEALTHY,
            'generation_id' => fake()->uuid(),
            'item_count' => fake()->numberBetween(0, 100),
            'projected_at' => now(),
            'last_success_at' => now(),
            'last_failure_at' => null,
            'stale_at' => now()->addMinutes(15),
            'error_code' => null,
            'error_summary' => null,
        ];
    }
}
