<?php

namespace Database\Factories;

use App\Models\RaidModelParameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RaidModelParameter>
 */
class RaidModelParameterFactory extends Factory
{
    protected $model = RaidModelParameter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => RaidModelParameter::BIAS_MULTIPLIERS,
            'value' => [],
            'sample_count' => 0,
            'computed_at' => now(),
        ];
    }
}
