<?php

namespace Database\Factories;

use App\Models\PayrollGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollGrade>
 */
class PayrollGradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'weekly_amount' => $this->faker->randomFloat(2, 100000, 5000000),
            'is_enabled' => true,
        ];
    }
}
