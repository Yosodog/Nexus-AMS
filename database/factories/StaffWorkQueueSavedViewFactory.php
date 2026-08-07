<?php

namespace Database\Factories;

use App\Models\StaffWorkQueueSavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StaffWorkQueueSavedView>
 */
class StaffWorkQueueSavedViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_id' => (string) Str::uuid(),
            'name' => fake()->words(2, true),
            'filters' => [
                'urgency' => fake()->randomElement(['urgent', 'attention', 'routine']),
                'sort' => 'age',
                'direction' => 'desc',
            ],
        ];
    }
}
