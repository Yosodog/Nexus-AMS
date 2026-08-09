<?php

namespace Database\Factories;

use App\Models\OperationsWorkCoordination;
use App\Models\OperationsWorkWatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationsWorkWatch>
 */
class OperationsWorkWatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coordination_id' => OperationsWorkCoordination::factory(),
            'user_id' => User::factory(),
            'muted_until' => null,
            'last_notified_at' => null,
        ];
    }
}
