<?php

namespace Database\Factories;

use App\Models\Nation;
use App\Models\PayrollGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayrollMember>
 */
class PayrollMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nation_id' => Nation::query()->inRandomOrder()->value('id') ?? $this->faker->numberBetween(1, 100000),
            'payroll_grade_id' => PayrollGrade::factory(),
            'is_active' => true,
        ];
    }
}
