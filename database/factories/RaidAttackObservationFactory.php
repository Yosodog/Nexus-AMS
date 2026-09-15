<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RaidAttackObservationFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => fake()->unique()->numberBetween(1, 100000000), 'war_id' => 1, 'att_id' => 1, 'def_id' => 2, 'occurred_at' => now(), 'observed_at' => now(), 'payload' => []];
    }
}
