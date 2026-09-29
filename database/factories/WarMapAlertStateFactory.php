<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WarMapAlertState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarMapAlertState>
 */
class WarMapAlertStateFactory extends Factory
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
            'nation_id' => fn (array $attributes): int => (int) User::query()->findOrFail($attributes['user_id'])->nation_id,
            'war_id' => fake()->unique()->numberBetween(1, 999999999),
            'armed' => true,
            'notification_sequence' => 0,
        ];
    }
}
