<?php

namespace Database\Factories;

use App\Models\OperationsTeamSavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperationsTeamSavedView>
 */
class OperationsTeamSavedViewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'team_key' => 'finance',
            'name' => fake()->unique()->words(2, true),
            'filters' => [
                'priority' => ['P0', 'P1'],
                'attention' => ['overdue', 'blocked'],
            ],
            'created_by_user_id' => User::factory(),
        ];
    }
}
